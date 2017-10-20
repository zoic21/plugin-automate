<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class automate extends eqLogic {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Methode static*************************** */

	public static function event() {
		$cmd = automateCmd::byId(init('id'));
		if (!is_object($cmd) || $cmd->getEqType() != 'automate') {
			throw new Exception(__('Commande ID automate inconnu, ou la commande n\'est pas de type automate : ', __FILE__) . init('id'));
		}
		if ($cmd->getLogicalId() == 'autoremote::notify') {
			if ($cmd->getCache('storeVariable', 'none') != 'none') {
				$cmd->askResponse(init('value'));
			}
			return;
		}
		$cmd->event(init('value'));
	}

	public static function flowParameters($_flow = '') {
		$return = array();
		foreach (ls(dirname(__FILE__) . '/../config/flow', '*') as $dir) {
			$path = dirname(__FILE__) . '/../config/flow/' . $dir;
			if (!is_dir($path)) {
				continue;
			}
			$files = ls($path, '*.json', false, array('files', 'quiet'));
			foreach ($files as $file) {
				try {
					$content = file_get_contents($path . '/' . $file);
					if (is_json($content)) {
						$return += json_decode($content, true);
					}
				} catch (Exception $e) {

				}
			}
		}
		if (isset($_flow) && $_flow != '') {
			if (isset($return[$_flow])) {
				return $return[$_flow];
			}
			return array();
		}
		return $return;
	}

	/*     * *********************Méthodes d'instance************************* */

	public function postSave() {
		if ($this->getConfiguration('autoremote::url') != '') {
			$cmd = $this->getCmd(null, 'autoremote::notify');
			if (!is_object($cmd)) {
				$cmd = new automateCmd();
				$cmd->setLogicalId('autoremote::notify');
				$cmd->setIsVisible(1);
				$cmd->setName(__('Notification', __FILE__));
			}
			$cmd->setType('action');
			$cmd->setSubType('message');
			$cmd->setEqLogic_id($this->getId());
			$cmd->save();
		}
	}

	public function generateXml($_flow) {
		$config = self::flowParameters($_flow);
		if (count($config) == 0) {
			throw new Exception(__('Impossible de trouver le fichier de config : ', __FILE__) . $_flow);
		}
		$replace = array(
			'#name#' => $this->getName(),
			'#eqLogic_id#' => $this->getId(),
			'#apikey#' => config::byKey('api'),
			'#network::external#' => network::getNetworkAccess('external'),
		);
		if (isset($config['commands']) && $config['commands'] > 0) {
			foreach ($config['commands'] as &$command) {
				$cmd = $this->getCmd(null, $command['logicalId']);
				if (!is_object($cmd)) {
					$cmd = new automateCmd();
					$cmd->setEqLogic_id($this->getId());
				} else {
					$command['name'] = $cmd->getName();
					if (isset($command['display'])) {
						unset($command['display']);
					}
				}
				utils::a2o($cmd, $command);
				$cmd->save();
				$replace['#' . $cmd->getLogicalId() . '_id#'] = $cmd->getId();
			}
		}

		if (isset($config['configuration']) && $config['configuration'] > 0) {
			foreach ($config['configuration'] as $key => $parameter) {
				$default = '';
				if (isset($parameter['default'])) {
					$default = $parameter['default'];
				}
				$replace['#' . $key . '#'] = $this->getConfiguration('automate::' . $_flow . '::' . $key, $default);
			}
		}

		$dir = dirname(__FILE__) . '/../../../../tmp/automate';
		if (file_exists($dir)) {
			rrmdir($dir);
		}
		mkdir($dir);
		foreach ($config['profil'] as $profil) {
			$xml = str_replace(array_keys($replace), $replace, file_get_contents(dirname(__FILE__) . '/../config/flow/' . $profil));
			file_put_contents($dir . '/' . basename(dirname(__FILE__) . '/../config/flow/' . $profil), $xml);
		}

		return str_replace(array_keys($replace), $replace, $xml);
	}

	/*     * **********************Getteur Setteur*************************** */
}

class automateCmd extends cmd {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Methode static*************************** */

	/*     * *********************Methode d'instance************************* */

	public function execute($_options = array()) {
		$eqLogic = $this->getEqLogic();
		if ($this->getLogicalId() == 'autoremote::notify') {
			$url = 'https://autoremotejoaomgcd.appspot.com/sendnotification?key=' . $eqLogic->getConfiguration('autoremote::key');
			if ($eqLogic->getConfiguration('autoremote::password') != '') {
				$url .= '&password=' . urlencode($eqLogic->getConfiguration('autoremote::password'));
			}
			if (isset($_options['message']) && (!isset($_options['title']) || $_options['title'] == '')) {
				$_options['title'] = $_options['message'];
				unset($_options['message']);
			}
			if (isset($_options['title'])) {
				$url .= '&title=' . urlencode($_options['title']);
			}
			if (isset($_options['message'])) {
				$url .= '&text=' . urlencode($_options['message']);
			}
			if (isset($_options['files']) && count($_options['files']) > 0) {
				$foundfile = null;
				foreach ($_options['files'] as $file) {
					$pathinfo = pathinfo($file);
					if (in_array($pathinfo['extension'], array('jpg', 'png', 'gif'))) {
						$foundfile = $file;
						break;
					}
				}
				if ($foundfile !== null) {
					$url .= '&picture=' . urlencode(network::getNetworkAccess('external') . '/core/php/downloadFile.php?apikey=' . config::byKey('api') . '&pathfile=' . urlencode($foundfile));
				}
			}
			if (isset($_options['answer'])) {
				$i = 1;
				foreach ($_options['answer'] as $answer) {
					$url .= '&action' . $i . '=ask=:=' . urlencode(network::getNetworkAccess('external') . '/core/api/jeeApi.php?apikey=' . config::byKey('api') . '&type=automate&id=' . $this->getId() . '&value=' . urlencode($answer)) . '&action' . $i . 'name=' . urlencode($answer) . '&action' . $i . 'icon=navigation_accept';
					if ($i >= 3) {
						break;
					}
					$i++;
				}
				$url .= '&statusbaricon=action_help';
			} else {
				$url .= '&statusbaricon=action_about';
			}
			$request_http = new com_http($url);
			$request_http->exec();
		}
	}

	/*     * **********************Getteur Setteur*************************** */
}

?>
