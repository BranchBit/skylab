<?php
namespace Kunstmaan\Skylab\Command;

use Kunstmaan\Skylab\Application;
use Kunstmaan\Skylab\Exceptions\AccessDeniedException;
use Symfony\Component\Yaml\Exception\RuntimeException;

class SelfUpdateCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->addDefaults()
            ->setName('self-update')
            ->setDescription('Updates skylab.phar to the latest version.')
            ->setHelp(<<<EOT
The <info>self-update</info> command will check if there is an updated skylab.phar released and updates if it is.

<info>php skylab.phar self-update</info>

EOT
            );
    }

    /**
     * @return int
     * @throws \Symfony\Component\Yaml\Exception\RuntimeException
     * @throws \Exception
     */
    protected function doExecute()
    {
        $cacheDir = sys_get_temp_dir();

        $localFilename = realpath($_SERVER['argv'][0]) ? : $_SERVER['argv'][0];

        // Check if current dir is writable and if not try the cache dir from settings
        $tmpDir = is_writable(dirname($localFilename)) ? dirname($localFilename) : $cacheDir;
        $tempFilename = $tmpDir . '/' . basename($localFilename, '.phar') . '-temp.phar';

        // check for permissions in local filesystem before start connection process
        if (!is_writable($tmpDir)) {
            throw new RuntimeException('Skylab update failed: the "' . $tmpDir . '" directory used to download the temp file could not be written');
        }

        $username = null;
        $password = null;
        $url = 'https://api.github.com/repos/kunstmaan/skylab/releases';
        try {
           $json = $this->remoteProvider->curl($url, null, null, 60);
        } catch (AccessDeniedException $e) {
           $this->dialogProvider->logWarning('The url ' . $url . ' has reached the api limit, please provide a login/password.');
           $username = $this->dialogProvider->askFor('Username:');
           $password = $this->dialogProvider->askHiddenResponse('Password:');
           $json = $this->remoteProvider->curl($url, null, null, 60, $username, $password);
        }
        $data = json_decode($json, true);

        if ($data == null){
            $this->dialogProvider->logError("Unable to fetch the Github releases", false);
        }

        usort($data, function ($a, $b) {
            return version_compare($a["tag_name"], $b["tag_name"]) * -1;
        });

        $latest = $data[0];
        if (version_compare(Application::VERSION, $latest["tag_name"]) < 0) {
            $this->dialogProvider->logTask('New release found: ' . $latest["tag_name"] . ', updating...');
            $this->remoteProvider->curl($latest["assets"][0]["url"], $latest["assets"][0]["content_type"], $tempFilename, 0, $username, $password);
            if (!file_exists($tempFilename)) {
                $this->dialogProvider->logError('The download of the new Skylab version failed for an unexpected reason');

                return 1;
            }
            try {
                chmod($tempFilename, 0777 & ~umask());
                // test the phar validity
                $phar = new \Phar($tempFilename);
                // free the variable to unlock the file
                unset($phar);
                $this->processProvider->executeSudoCommand("mv " . $tempFilename . " " . $localFilename);
            } catch (\Exception $e) {
                unlink($tempFilename);
                if (!$e instanceof \UnexpectedValueException && !$e instanceof \PharException) {
                    throw $e;
                }
                $this->dialogProvider->logError('The download is corrupted (' . $e->getMessage() . '). Please re-run the self-update command to try again.');

                return 1;
            }
        } else {
            $this->dialogProvider->logTask('You are running the latest release: ' . $latest["tag_name"]);
        }

        return 0;
    }

}
