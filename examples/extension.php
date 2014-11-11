<?php

include_once '../src/StateMachine.php';
use Fluxer\StateMachine;

/**
 * Custom State Machine that allows to know if states are final (end of workflow)
 */
class MyStateMachine extends StateMachine {

	function getFinalStates() {
		$configurados = array_keys($this->configurations);
		$finals = [];
		/** @var  $config \Fluxer\StateConfigHolder */
		foreach ($this->configurations as $state => $config) {
			if (!$config->transitions) {
				$finals[$state] = true;
				continue;
			}
			foreach ($config->transitions as $trigger => $transition) {
				if (!in_array($transition->destination, $configurados))
					$finales[$transition->destination] = true;
			}
		}
		return array_keys($finals);
	}

	function isStateFinal() {
		// if there is no configuration or no transitions, the current state is final
		$config = $this->getConfig($this->state, false);
		if (!$config)
			return true;
		return empty($config->transitions);
	}

}