<?php

/*
 * EconomyS, the massive economy plugin with many features for PocketMine-MP
 * Copyright (C) 2013-2015  onebone <jyc00410@gmail.com>
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

namespace onebone\economyusury;

use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\Listener;
use pocketmine\item\Item;

use onebone\economyusury\commands\UsuryCommand;

class EconomyUsury extends PluginBase implements Listener{
	private $usuryHosts, $msg_queue;
	
	public function onEnable(){
		if(!file_exists($this->getDataFolder())){
			mkdir($this->getDataFolder());
		}
		
		if(!is_file($this->getDataFolder()."usury.dat")){
			file_put_contents($this->getDataFolder()."usury.dat", serialize([]));
		}
		if(!is_file($this->getDataFolder()."msg_queue.dat")){
			file_put_contents($this->getDataFolder()."msg_queue.dat", serialize([]));
		}
		
		$this->msg_queue = unserialize(file_get_contents($this->getDataFolder()."msg_queue.dat"));
		$this->usuryHosts = unserialize(file_get_contents($this->getDataFolder()."usury.dat"));
		
		$commandMap = $this->getServer()->getCommandMap();
		$commandMap->register("usury", new UsuryCommand("usury", $this));
		
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		
		foreach($this->usuryHosts as $host => $val){
			foreach($val["players"] as $player => $data){
				$this->usuryHosts[$host]["players"][$player][3] = time();
				$tid = $this->getServer()->getScheduler()->scheduleDelayedTask(new DueTask($this, Item::get($data[0], $data[1], $data[2]), $player, $host), $data[4])->getTaskId();
				$this->usuryHosts[$host]["players"][$player][5] = $tid;
			}
		}
	}
	
	public function onDisable(){
		$this->validateDue();
		
		$saves = [
			"usury.dat" => $this->usuryHosts,
			"msg_queue.dat" => $this->msg_queue
		];
		foreach($saves as $fileName => $data){
			file_put_contents($this->getDataFolder().$fileName, serialize($data));
		}
	}
	
	public function validateDue($cancelTask = true){
		$now = time();
		foreach($this->usuryHosts as $host => $val){
			foreach($val["players"] as $player => $data){
				$reduce = (($now - $data[3]) * 20);
				$this->usuryHosts[$host]["players"][$player][3] = time();
				$this->usuryHosts[$host]["players"][$player][4] -= $reduce;
				if($cancelTask){
					if($this->getServer()->getScheduler()->isQueued($data[5])){
						$this->getServer()->getScheduler()->cancelTask($data[5]);
					}
				}
			}
		}
	}
	
	public function onJoinEvent(PlayerJoinEvent $event){
		$player = $event->getPlayer();
		if(isset($this->msg_queue[$player->getName()])){
			foreach($this->msg_queue[$player->getName()] as $msg){
				$player->sendMessage($msg);
			}
			unset($this->msg_queue[$player->getName()]);
		}
	}
	
	public function usuryHostExists($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);
		
		return isset($this->usuryHosts[$player]) === true;
	}
	
	public function openUsuryHost($player, $interest, $interval){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);
		
		if(isset($this->usuryHosts[$player])){
			return false;
		}
		
		$this->usuryHosts[$player] = [
			$interest, $interval,
			"players" => []
		];
		return true;
	}
	
	public function closeUsuryHost($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);
		
		if(!isset($this->usuryHosts[$player])){
			return false;
		}
		
		foreach($this->usuryHosts[$player]["players"] as $key => $username){ // TODO: Debug here
			if(($player = $this->getServer()->getPlayerExact($username))){
				$player->getInventory()->addItem(Item::get($this->usuryHosts[$player]["players"][$key][0], $this->usuryHosts[$player]["players"][$key][1], $this->usuryHosts[$player]["players"][$key][2]));
				continue;
			}
			$this->addItem($username, Item::get($this->usuryHosts[$player]["players"][$key][0], $this->usuryHosts[$player]["players"][$key][1], $this->usuryHosts[$player]["players"][$key][2]));
		}
		
		unset($this->usuryHosts[$player]);
		return true;
	}
	
	public function isPlayerJoinedHost($player, $host){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);
		
		return isset($this->usuryHosts[$host]["players"][$player]) === true;
	}
	
	public function getAllHosts(){
		return $this->usuryHosts;
	}
	
	public function joinHost($player, $host, $due, Item $guarantee){
		if($guarantee === null){
			throw new \Exception("Item cannot be null");
		}
		
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);
		
		if(isset($this->usuryHosts[$host]["players"][$player])){
			return false;
		}
		if(!$this->containsItem($player, $guarantee)){
			return false;
		}
		$this->removeItem($player, $guarantee);
		
		$this->usuryHosts[$host]["players"][$player] = [
			$guarantee->getId(), $guarantee->getDamage(), $guarantee->getCount(), time(), $due * 1200
		];
		
		$tid = $this->getServer()->getScheduler()->scheduleDelayedTask(new DueTask($this, $guarantee, $player, $host), $due * 1200)->getTaskId();
		$this->usuryHosts[$host]["players"][$player][5] = $tid;
		return true;
	}
	
	public function getJoinedPlayers($host){
		if(!isset($this->usuryHosts[$host])){
			return false;
		}
		return $this->usuryHosts[$host]["players"];
	}
	
	public function removePlayerFromHost($player, $host){
		if(!isset($this->usuryHosts[$host]["players"][$player])){
			return false;
		}
		if($this->getServer()->getScheduler()->isQueued($this->usuryHosts[$host]["players"][$player][5])){
			$this->getServer()->getScheduler()->cancelTask($this->usuryHosts[$host]["players"][$player][5]);
		}
		unset($this->usuryHosts[$host]["players"][$player]);
		return true;
	}
	
	public function queueMessage($player, $message){
		if(($p = $this->getServer()->getPlayerExact($player)) instanceof Player){
			$p->sendMessage($message);
			return false;
		}
		$this->msg_queue[$player][] = $message;
		return true;
	}
	
	public function containsItem($player, Item $i){
		if(($p = $this->getServer()->getPlayerExact($player)) instanceof Player){
			return $p->getInventory()->contains($i);
		}
		
		$data = $this->getServer()->getOfflinePlayerData($player);
		$count = 0;
		foreach($data->Inventory as $key => $item){
			if($key > 8){
				if($item["id"] == $i->getId() and $item["Damage"] == $i->getDamage()){
					$count += $item["Count"];
					if($count >= $i->getCount()) return true;
				}
			}
		}
		return false;
	}
	
	public function addItem($player, Item $i){
		if(($p = $this->getServer()->getPlayerExact($player)) instanceof Player){
			$p->getInventory()->addItem($i);
		}
		
		$data = $this->getServer()->getOfflinePlayerData($player);
		$count = $i->getCount();
		foreach($data->Inventory as $key => $item){
			if($key > 8){
				if($item["id"] == $i->getId() and $item["Damage"] == $i->getDamage()){
					$giveCnt = min($i->getMaxStackSize() - $item["Count"], $count);
					$count -= $giveCnt;
					
					$item["Count"] += $giveCnt;
					if($count <= 0) goto save;
				}
			}
		}
		foreach($data->Inventory as $key => $item){
			if($key > 8){
				if($item["id"] == 0){
					$giveCnt = min($i->getMaxStackSize(), $count);
					$count -= $giveCnt;
					
					$item["id"] = $i->getId();
					$item["Damage"] = $i->getDamage();
					$item["Count"] = $giveCnt;
					if($count <= 0) break;
				}
			}
		}
		save:
		$this->getServer()->saveOfflinePlayerData($player, $data);
	}
	
	public function removeItem($player, Item $i){
		if(($p = $this->getServer()->getPlayerExact($player)) instanceof Player){
			$p->getInventory()->removeItem($i);
			return;
		}
		$data = $this->getServer()->getOfflinePlayerData($player);
		$count = $i->getCount();
		foreach($data->Inventory as $key => $item){
			if($key > 8){
				if($item["id"] == $i->getId() and $item["Damage"] == $i->getDamage()){
					$removeCnt = min($count, $item["Count"]);
					$count -= $removeCnt;
					
					$item["Count"] -= $removeCnt;
					if($item["Count"] <= 0){
						$item["id"] = 0;
						$item["Damage"] = 0;
					}
					if($count <= 0){
						break;
					}
				}
			}
		}
		$this->getServer()->saveOfflinePlayerData($player, $data);
	}
}