<?php
namespace go\modules\community\multi_instance;

use go\core\App;
use go\core\ErrorHandler;
use go\core\http\Request;
use go\core\http\Response;
use go\core\Installer;
use go\core\webclient\Extjs3;
use go\modules\community\multi_instance\model\Instance;

class Module extends \go\core\Module {
	/**
	 * The development status of this module
	 * @return string
	 */
	public function getStatus() : string{
		return self::STATUS_STABLE;
	}

	public function getAuthor(): string
	{
		return "Intermesh BV";
	}


	protected function afterInstall(\go\core\model\Module $model): bool
	{
		
		$cron = new \go\core\model\CronJobSchedule();
		$cron->moduleId = $model->id;
		$cron->name = "InstanceCron";
		$cron->expression = "* * * * *";
		$cron->description = "Cron for instances";
		
		if(!$cron->save()) {
			throw new \Exception("Failed to save cron job: " . var_export($cron->getValidationErrors(), true));
		}
		
		
		$cron = new \go\core\model\CronJobSchedule();
		$cron->moduleId = $model->id;
		$cron->name = "DeactivateTrials";
		$cron->expression = "0 10 * * *";
		$cron->description = "Deactivate trials";
		
		if(!$cron->save()) {
			throw new \Exception("Failed to save cron job: " . var_export($cron->getValidationErrors(), true));
		}
		
		return parent::afterInstall($model);
	}

	public function defineListeners()
	{
		parent::defineListeners();

		go()->getInstaller()->on(Installer::EVENT_UPGRADE, static::class, 'upgradeInstances');

		go()->on(App::EVENT_INDEX, static::class, 'checkUrl');
	}

	public static function checkUrl() {

		//Skip localhost for development
		$host = Request::get()->getHost();
		if($host === 'localhost' || $host === 'host.docker.internal') {
			return;
		}

		$configUrl = go()->getSettings()->URL;
		$p = parse_url($configUrl, PHP_URL_HOST);

		if($p != Request::get()->getHost())  {
			Extjs3::get()->renderPage(
				"<section><div class='card'><h1>" .go()->t("Not found") . "</h1><p>" .
				go()->t("Sorry, this instance wasn't found. Please double check the URL you've entered.")
				."</p></div></section>"

				,
				go()->t("Not found"));
			exit();
		}

	}

	public static function upgradeInstances() {

		echo "\nUpgrading all instances\n";
		echo "-------------------------------\n\n";

		$failed = 0;

		foreach(Instance::find()->orderBy(['enabled' => 'DESC', 'isTrial' => 'ASC', 'lastLogin' => 'DESC']) as $instance) {
			if(!$instance->isInstalled()) {
				echo "Skipping not installed instance: " . $instance->hostname ."\n";
				continue;
			}

			if(!$instance->enabled) {
				echo "Skipping disabled instance: " . $instance->hostname ."\n";
				continue;
			}

			echo "Upgrading instance: " . $instance->hostname . ": ";
			flush();
			try {
				$success = $instance->upgrade();
			} catch(\Throwable $e) {
				ErrorHandler::logException($e, "Failed to upgrade " . $instance->hostname);
				$success = false;
			}

			echo $success ? "ok" : "!!! FAILED !!!";

			if(!$success) {
				$failed++;
			}

			echo "\n";
		}

		if(!$failed) {
			echo "All OK!\n";
		} else{
			echo "\n\nWARNING: There are $failed failed upgrades. Please investigate!\n\n";
		}
	}


	public function downloadSiteConfig() {

		Response::get()->setContentType('text/plain');
		Response::get()->sendHeaders();

		$i = [];

		foreach(Instance::find() as $instance) {
			$tld = $this->getTLD($instance->hostname);
			if(!isset($i[$tld])) {
				$i[$tld] = [];
			}
			$version = $instance->getMajorVersion();
			if(empty($version)) {
				continue;
			}
			if(!isset($i[$tld][$version])) {
				$i[$tld][$version] = [];
			}

			$i[$tld][$version][] = $instance->hostname;
		}

	//	$i['6.5'] = ['test.65', 'test2.65', 'test.65', 'test2.65', 'test.65', 'test2.65'];

		$tpl = file_get_contents(__DIR__ . '/site-conf.tpl');

		foreach($i as $tld => $versions) {
			foreach ($versions as $version => $hostnames) {
				echo $this->parseTemplate($tpl, $version, $hostnames, $tld);
			}
		}

		echo $this->parseTemplate($tpl, "DEFAULT", [$_SERVER['SERVER_NAME']], $this->getTLD($_SERVER['SERVER_NAME']));
	}

	private function getTLD($hostname) : string {
		$hostname = Request::get()->getHost();
		$dotPos = strpos($hostname, '.');

		if(!$dotPos) {
			return "localdomain";
		}

		return substr($hostname, $dotPos + 1);
	}

	private function parseTemplate($tpl, $version, $hostnames, $tld) {



		// Each instance must have a dedicated WOPI subdomain for Microsoft: https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/online/build-test-ship/environments#wopi-discovery-urls
		$wopialiases = array_map(function($hostname) {
			$parts = explode(".", $hostname);
			$first = array_shift($parts);
			$alias = $first . '.wopi';

			if(count($parts)) {
				$alias .= '.' . implode("." , $parts);
			}
			return $alias;
		}, $hostnames);

		$replacements = [
			'{docroot}' => $version == 'DEFAULT' ? go()->getEnvironment()->getInstallFolder()->getPath() : '/usr/local/share/groupoffice-' . $version . '/www',
			'{aliases}' => $version == 'DEFAULT' ? '*.' . $tld .' ' .$this->implode($hostnames) : $this->implode($hostnames),
			'{wopialiases}' => $version == 'DEFAULT' ? '*.wopi.' . $tld .' ' .$this->implode($wopialiases) : $this->implode($wopialiases),
			'{tld}' => $tld,
			'{servername}' => strtolower(str_replace('.', '', $version)) . '.' . $tld,
			'{version}' => str_replace('.', '', $version)
		];

		return str_replace(array_keys($replacements), array_values($replacements), $tpl);


	}

	private function implode($aliases) {

		$str = "";
		$i = 0;

		foreach($aliases as $a) {
			$str .= $a;
			$i++;

			if($i == 4) {
				$i = 0;

				$str .= " \\\n    ";

			} else{
				$str .= ' ';
			}
		}

		return trim($str, " \n\\");
	}
}
