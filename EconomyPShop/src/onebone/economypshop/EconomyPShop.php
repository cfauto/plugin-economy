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

namespace onebone\economypshop;

use onebone\economyapi\EconomyAPI;
use onebone\itemcloud\ItemCloud;
use onebone\itemcloud\MainClass;
use pocketmine\block\utils\SignText;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

class EconomyPShop extends PluginBase implements Listener {
	private $placeQueue, $shop, $shopText, $lang, $tap;

	/** @var MainClass */
	private $itemcloud;

	public function onEnable() {
		if(!file_exists($this->getDataFolder())) {
			mkdir($this->getDataFolder());
		}

		$this->shop = (new Config($this->getDataFolder() . "Shops.yml", Config::YAML))->getAll();

		if(!class_exists("\\onebone\\itemcloud\\MainClass", false)) {
			$this->getLogger()->critical("[DEPENDENCY] Please install ItemCloud plugin to use PShop plugin.");
			return;
		}
		if(!class_exists("\\onebone\\economyapi\\EconomyAPI", false)) {
			$this->getLogger()->critical("[DEPENDENCY] Please install EconomyAPI plugin to use PShop plugin.");
			return;
		}

		$this->saveResource("ShopText.yml");
		$this->saveResource("language.properties");
		$this->saveDefaultConfig();

		$this->shopText = (new Config($this->getDataFolder() . "ShopText.yml", Config::YAML));
		$this->lang = (new Config($this->getDataFolder() . "language.properties", Config::PROPERTIES));

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->itemcloud = MainClass::getInstance();

		$this->tap = [];
		$this->placeQueue = [];
	}

	public function onDisable() {
		$file = new Config($this->getDataFolder() . "Shops.yml", Config::YAML);
		$file->setAll($this->shop);
		$file->save();
	}

	public function onSignChange(SignChangeEvent $event) {
		$line = $event->getOldText()->getLines();
		if(($val = $this->getTag($line[0])) !== false) {
			$player = $event->getPlayer();
			if(!$player->hasPermission("economypshop.shop.create")) {
				$player->sendMessage($this->getMessage("no-permission-create-shop"));
				return;
			}

			if(in_array(strtolower($event->getBlock()->getPos()->getWorld()->getFolderName()), $this->getConfig()->get("disallow-worlds", []))) {
				$player->sendMessage($this->getMessage("disallowed-world"));
				return;
			}

			$money = EconomyAPI::getInstance()->myMoney($player->getName());
			if($money < $this->getConfig()->get("shop-tax")) {
				$player->sendMessage($this->getMessage("no-shop-tax"));
				return;
			}

			EconomyAPI::getInstance()->reduceMoney($player->getName(), $this->getConfig()->get("shop-tax"));

			$cost = $line[1];
			$item = $line[2];
			$amount = $line[3];

			if(!is_numeric($cost) or !is_numeric($amount)) {
				$player->sendMessage($this->getMessage("insert-right-format"));
				return;
			}

			if($cost < 0 or $amount < 1 or (int) $amount != $amount) {
				$player->sendMessage($this->getMessage("wrong-num"));
				return;
			}

			$item = LegacyStringToItemParser::getInstance()->parse($item);
			if(!$item instanceof Item) {
				$player->sendMessage($this->getMessage("item-not-support", array($line[2], "", "")));
				return;
			}

			$block = $event->getBlock();
			$pos = $block->getPos();
			$this->shop[$pos->getX() . ":" . $pos->getY() . ":" . $pos->getZ() . ":" . $pos->getWorld()->getFolderName()] = [
					"x" => $pos->getX(),
					"y" => $pos->getY(),
					"z" => $pos->getZ(),
					"level" => $pos->getWorld()->getFolderName(),
					"owner" => $player->getName(),
					"price" => (int) $line[1],
					"item" => (int) $item->getID(),
					"itemName" => $line[2],
					"meta" => (int) $item->getMeta(),
					"amount" => (int) $line[3]
			];

			$mu = EconomyAPI::getInstance()->getMonetaryUnit();

			$event->setNewText(new SignText([
				str_replace("%MONETARY_UNIT%", $mu, $val[0]),
				str_replace(["%MONETARY_UNIT%", "%1"], [$mu, $cost], $val[1]),
				str_replace(["%MONETARY_UNIT%", "%2"], [$mu, $item->getName()], $val[2]),
				str_replace(["%MONETARY_UNIT%", "%3"], [$mu, $amount], $val[3])
			]));

			$player->sendMessage($this->getMessage("shop-created", [$item->getName(), $cost, $amount]));
		}
	}

	public function getTag($firstLine) {
		foreach($this->shopText->getAll() as $key => $val) {
			if($key == $firstLine) {
				return $val;
			}
		}
		return false;
	}

	public function getMessage($key, $val = ["%1", "%2", "%3"]) {
		if($this->lang->exists($key)) {
			return str_replace(["%1", "%2", "%3", "%MONETARY_UNIT%"], [$val[0], $val[1], $val[2], EconomyAPI::getInstance()->getMonetaryUnit()], $this->lang->get($key));
		}
		return "There's no message named \"$key\"";
	}

	public function onBlockBreak(BlockBreakEvent $event) {
		$block = $event->getBlock();
		$pos = $block->getPos();
		$loc = $pos->getX() . ":" . $pos->getY() . ":" . $pos->getZ() . ":" . $pos->getWorld()->getFolderName();
		if(isset($this->shop[$loc])) {
			$player = $event->getPlayer();
			$shop = $this->shop[$loc];

			if($shop["owner"] == $player->getName()) {
				unset($this->shop[$loc]);
				$player->sendMessage($this->getMessage("shop-removed"));
			}else{
				if($player->hasPermission("economypshop.shop.destroy.others")) {
					$player->sendMessage($this->getMessage("shop-others-removed", [$shop["owner"], "%2", "%3"]));
					unset($this->shop[$loc]);
				}else{
					$player->sendMessage($this->getMessage("no-permission-remove-shop"));
					$event->cancel();
				}
			}
		}
	}

	public function onBlockTouch(PlayerInteractEvent $event) {
		if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
			return;
		}
		$block = $event->getBlock();
		$pos = $block->getPos();
		$loc = $pos->getX() . ":" . $pos->getY() . ":" . $pos->getZ() . ":" . $pos->getWorld()->getFolderName();
		if(isset($this->shop[$loc])) {
			$player = $event->getPlayer();
			if($player->hasPermission("economypshop.shop.buy")) {
				$shop = $this->shop[$loc];

				if($shop["owner"] == $player->getName()) {
					$player->sendMessage($this->getMessage("same-player"));
					return;
				}
				if($shop["price"] < 0 or $shop["amount"] < 1) {
					$player->sendMessage($this->getMessage("wrong-num"));
					return;
				}

				$now = microtime(true);
				if(!isset($this->tap[$player->getName()]) or $now - $this->tap[$player->getName()][1] >= 1.5 or $this->tap[$player->getName()][0] !== $loc) {
					$this->tap[$player->getName()] = [$loc, $now];
					$player->sendMessage($this->getMessage("tap-again", [$shop["itemName"], $shop["price"], $shop["amount"]]));
					return;
				}else{
					unset($this->tap[$player->getName()]);
				}

				if(($cloud = $this->itemcloud->getCloudForPlayer($shop["owner"])) instanceof ItemCloud) {
					if($shop["amount"] > $cloud->getCount($shop["item"], $shop["meta"])) {
						$player->sendMessage($this->getMessage("no-stock"));
					}else{
						if($player->getInventory()->canAddItem(($item = ItemFactory::getInstance()->get($shop["item"], $shop["meta"], $shop["amount"]))) === false) {
							$player->sendMessage($this->getMessage("no-space"));
						}else{
							$api = EconomyAPI::getInstance();
							if($api->myMoney($player) > $shop["price"]) {
								$player->getInventory()->addItem($item);

								$api->reduceMoney($player, $shop["price"]);
								$player->sendMessage($this->getMessage("bought-item", [$shop["item"] . ":" . $shop["meta"], $shop["price"], $shop["amount"]]));
								$cloud->removeItem($shop["item"], $shop["meta"], $shop["amount"]);
								$api->addMoney($shop["owner"], $shop["price"]);
							}else{
								$player->sendMessage($this->getMessage("no-money"));
							}
						}
					}
				}else{
					$player->sendMessage($this->getMessage("shop-owner-no-account"));
				}
				$event->cancel();
				if($event->getItem()->canBePlaced()) {
					$this->placeQueue[$player->getName()] = true;
				}
			}else{
				$player->sendMessage($this->getMessage("no-permission-buy"));
			}
		}
	}

	public function onBlockPlace(BlockPlaceEvent $event) {
		$user = $event->getPlayer()->getName();
		if(isset($this->placeQueue[$user])) {
			$event->cancel();
			unset($this->placeQueue[$user]);
		}
	}
}
