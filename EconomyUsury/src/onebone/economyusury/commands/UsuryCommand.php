<?php

/*
 * EconomyS, the massive economy plugin with many features for PocketMine-MP
 * Copyright (C) 2013-2021  onebone <me@onebone.me>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace onebone\economyusury\commands;

use onebone\economyapi\EconomyAPI;
use onebone\economyusury\EconomyUsury;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use pocketmine\utils\TextFormat;

class UsuryCommand extends Command implements PluginOwned, Listener {
	private $requests = [];
	private $plugin;

	public function __construct($cmd, EconomyUsury $plugin) {
		parent::__construct($cmd, "Usury master command", "/$cmd <host|request|cancel|list|left>");

		$this->plugin = $plugin;
		$plugin->getServer()->getPluginManager()->registerEvents($this, $plugin);
	}

	public function onPlayerJoin(PlayerJoinEvent $event) {
		if(isset($this->requests[strtolower($event->getPlayer()->getName())])) {
			$event->getPlayer()->sendMessage($this->getOwningPlugin()->getMessage("received-request", [count($this->requests[strtolower($event->getPlayer()->getName())]), "%2"]));
		}
	}

	public function getOwningPlugin(): Plugin {
		return $this->plugin;
	}

	public function execute(CommandSender $sender, string $label, array $params): bool {
		$plugin = $this->plugin;

		if(!$this->plugin->isEnabled() or !$this->testPermission($sender)) {
			return false;
		}

		switch (array_shift($params)) {
			case "host":
				switch (array_shift($params)) {
					case "open":
						if($plugin->usuryHostExists($sender->getName())) {
							$sender->sendMessage($plugin->getMessage("host-exists"));
							break;
						}

						$interest = array_shift($params);
						$interval = array_shift($params);

						if(!is_numeric($interest) or !is_numeric($interval)) {
							$sender->sendMessage("Usage: /usury host open <interest> <interval>");
							break;
						}

						$plugin->openUsuryHost($sender->getName(), $interest, $interval);
						$plugin->getServer()->broadcastMessage($plugin->getMessage("host-open", [$sender->getName(), "%2"]));
						break;
					case "close":
						$success = $plugin->closeUsuryHost($sender->getName());
						if($success) {
							$sender->sendMessage($plugin->getMessage("host-closed"));
						}else{
							$sender->sendMessage($plugin->getMessage("no-host"));
						}
						break;
					case "accept":
						$player = strtolower(array_shift($params));
						if(trim($player) == "") {
							$sender->sendMessage("Usage: /usury host accept <player>");
							break;
						}
						if(isset($this->requests[strtolower($sender->getName())][$player])) {
							$plugin->joinHost($player, $sender->getName(), $this->requests[strtolower($sender->getName())][$player][1], $this->requests[strtolower($sender->getName())][$player][0], $this->requests[strtolower($sender->getName())][$player][2]);
							$sender->sendMessage($plugin->getMessage("accepted-player", [$player, "%2"]));
							$plugin->queueMessage($player, $plugin->getMessage("accepted-by-host", [$sender->getName(), "%2"]));
							EconomyAPI::getInstance()->addMoney($player, $this->requests[strtolower($sender->getName())][$player][2], true, "EconomyUsury");
							EconomyAPI::getInstance()->reduceMoney($sender->getName(), $this->requests[strtolower($sender->getName())][$player][2], true, "EconomyUsury");
							unset($this->requests[strtolower($sender->getName())][$player]);
							return true;
						}
						$sender->sendMessage($plugin->getMessage("no-requester", [$player, "%2"]));
						break;
					case "decline":
						$player = strtolower(array_shift($params));
						if(trim($player) === "") {
							$sender->sendMessage("Usage: /usury host decline <player>");
							break;
						}
						if(isset($this->requests[strtolower($sender->getName())][$player])) {
							unset($this->requests[strtolower($sender->getName())][$player]);
							$plugin->queueMessage($player, $plugin->getMessage("request-declined-by-host", [$sender->getName(), "%2"]));
							$sender->sendMessage($plugin->getMessage("request-declined", [$sender->getName(), "%2"]));
						}else{
							$sender->sendMessage($plugin->getMessage("no-requester", [$player, "%2"]));
						}
						break;
					case "list":
						switch (array_shift($params)) {
							case "c":
							case "client":
								$players = $plugin->getJoinedPlayers($sender->getName());
								if($players === false or count($players) <= 0) {
									$sender->sendMessage($plugin->getMessage("no-joined-host"));
									break;
								}
								$msg = $plugin->getMessage("list-clients-top", [count($players)]);
								foreach($players as $player => $condition) {
									$msg .= $plugin->getMessage("list-clients", [$player, $condition[2], Item::get($condition[0], $condition[1], $condition[2])->getName(), $condition[2]]);
								}
								$sender->sendMessage($msg);
								break;
							default:
								if(!isset($this->requests[strtolower($sender->getName())]) or count($this->requests[strtolower($sender->getName())]) <= 0) {
									$sender->sendMessage($plugin->getMessage("no-request-received"));
									return true;
								}
								$msg = $plugin->getMessage("list-requesters-top", [count($this->requests[strtolower($sender->getName())])]);
								foreach($this->requests[strtolower($sender->getName())] as $player => $condition) {
									$msg .= $plugin->getMessage("list-requesters", [$player, $condition[0]->getCount(), $condition[0]->getName(), $condition[1], $condition[2]]);
								}
								$sender->sendMessage($msg);
								break;
						}
						break;
					default:
						$sender->sendMessage("Usage: /usury host <open|close|accept|decline|list>");
				}
				break;
			case "request":
				$requestTo = strtolower(array_shift($params));
				$item = array_shift($params);
				$count = array_shift($params);
				$due = array_shift($params);
				$money = array_shift($params);
				if(trim($requestTo) == "" or trim($item) == "" or !is_numeric($count) or !is_numeric($due) or !is_numeric($money)) {
					$sender->sendMessage("Usage: /usury request <host> <guarantee item> <count> <due> <money>");
					break;
				}

				if(!$plugin->usuryHostExists($requestTo)) {
					$sender->sendMessage($plugin->getMessage("no-requested-host", [$requestTo, "%2"]));
					break;
				}

				if($requestTo === strtolower($sender->getName())) {
					$sender->sendMessage($plugin->getMessage("cant-join-own-host"));
					break;
				}

				if(isset($this->requests[$requestTo][strtolower($sender->getName())]) or $plugin->isPlayerJoinedHost($sender->getName(), $requestTo)) {
					$sender->sendMessage($plugin->getMessage("already-related", [$requestTo, "%2"]));
					break;
				}

				$item = Item::fromString($item);
				$item->setCount($count);
				if($sender->getInventory()->contains($item)) {
					$this->requests[$requestTo][strtolower($sender->getName())] = [$item, $due, $money];
					$sender->sendMessage($plugin->getMessage("sent-request", [$requestTo, "%2"]));
					if(($player = $plugin->getServer()->getPlayerExact($requestTo)) instanceof Player) {
						$player->sendMessage($plugin->getMessage("received-request-now", [$sender->getName(), "%2"]));
					}
				}else{
					$sender->sendMessage($plugin->getMessage("no-guarantee"));
				}
				break;
			case "cancel":
				$host = strtolower(array_shift($params));
				if(trim($host) === "") {
					$sender->sendMessage("Usage: /usury cancel <host>");
					break;
				}
				if(isset($this->requests[$host][strtolower($sender->getName())])) {
					unset($this->requests[$host][strtolower($sender->getName())]);
					$sender->sendMessage($plugin->getMessage("request-cancelled", [$host, "%2"]));
				}else{
					$sender->sendMessage($plugin->getMessage("no-request-sent", [$host, "%2"]));
				}
				break;
			case "list":
				switch (array_shift($params)) {
					case "joined":
					case "j":
						$hosts = $plugin->getHostsJoined($sender->getName());
						if(count($hosts) <= 0) {
							$sender->sendMessage($plugin->getMessage("no-host-joined"));
							break;
						}
						$msg = $plugin->getMessage("list-joined-top", [count($hosts)]);
						foreach($hosts as $host) {
							$msg .= $host . ", ";
						}
						$msg = substr($msg, 0, -2);
						$sender->sendMessage($msg);
						break;
					default:
						$msg = $plugin->getMessage("list-hosts-top", [count($plugin->getAllHosts())]);
						foreach($plugin->getAllHosts() as $host => $data) {
							$ic = TextFormat::GREEN;
							if($data[0] >= 50) {
								$ic = TextFormat::YELLOW;
							} elseif($data[0] >= 100) {
								$ic = TextFormat::RED;
							}
							$msg .= $plugin->getMessage("list-hosts", [$host, count($data["players"]), $ic, $data[0], $data[1]]);
						}
						$sender->sendMessage($msg);
				}
				break;
			case "left":
				$hosts = $plugin->getHostsJoined($sender->getName());
				if(count($hosts) <= 0) {
					$sender->sendMessage($plugin->getMessage("no-host-joined"));
					break;
				}
				$msg = $plugin->getMessage("list-left-top", [count($hosts)]);
				$all = $plugin->getAllHosts();
				foreach($hosts as $host) {
					$msg .= $plugin->getMessage("list-left", [$host, $all[$host]["players"][strtolower($sender->getName())][5]]);
				}
				$sender->sendMessage($msg);
				break;
			default:
				$sender->sendMessage("Usage: " . $this->getUsage());
		}
		return true;
	}
}
