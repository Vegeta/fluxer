<?php
/*
 * This file is part of the Fluxer package.
 *
 * (c) Manuel Gómez P.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fluxer;

/**
 * Simple state machine
 * @package Fluxer
 */
class StateMachine {

	protected $state;
	protected $onTrigger;
	protected $mutator;
	protected $configurations = array();
	protected $dataContext = null;
	protected $events = array(
		'entry' => array(),
		'exit' => array(),
		'unhandled' => array(),
		'transition' => array(),
	);

	protected $eventUpdater = null;

	function __construct($initialState = null, $stateMutator = null) {
		if ($initialState)
			$this->init($initialState);
		if (is_callable($stateMutator))
			$this->mutator = $stateMutator;
		$self = $this;
		$this->eventUpdater = function ($type, $state, $callable) use ($self) {
			$self->events[$type][$state][] = $callable;
		};
	}

	function init($state) {
		$this->state = $state;
		return $this;
	}

	/**
	 * Sets a function to be called whenever the internal state changes
	 * @param $callable
	 * @return $this
	 */
	function setStateMutator($callable) {
		$this->mutator = $callable;
		return $this;
	}

	function getState() {
		return $this->state;
	}

	/**
	 * Sets the default data to be used for conditionals and dynamic resolution in this state machine.
	 * Can be a callable function that returns the data at runtime.
	 * @param mixed $valueOrCallable Array, object or callable
	 * @return $this
	 */
	function withDataContext($valueOrCallable) {
		if ($valueOrCallable != null) {
			$this->dataContext = $valueOrCallable;
		} else {
			$this->dataContext = null;
		}
		return $this;
	}

	/**
	 * @return mixed|null Value of actual data context
	 */
	function getDataContext() {
		return $this->resolveData(null);
	}

	/**
	 * Sets a function to be called after a state transition occurs (fire method)
	 * @param $callable
	 * @return $this
	 */
	function onTransition($callable) {
		$this->events['transition'][] = $callable;
		return $this;
	}

	function onUnhandledTrigger($callable) {
		$this->events['unhandled'][] = $callable;
		return $this;
	}

	/**
	 * @return array List of configured states
	 */
	function listStates() {
		return array_keys($this->configurations);
	}

	function isInState($state) {
		if ($this->state == $state)
			return true;
		$parent = $this->getParent($this->state);
		if (!$parent)
			return false;
		$list = [$parent];
		while ($list) {
			$check = array_pop($list);
			if ($check->state == $state)
				return true;
			$parent = $this->getParent($check->parent);
			if ($parent)
				$list[] = $check;
		}
		return false;
	}

	protected function getParent($state) {
		if (!$state)
			return null;
		$config = $this->getConfig($state, false);
		if (!$config)
			return null;
		return $this->getConfig($config->parent, false);
	}

	/**
	 * Prepares a State configuration
	 * @param $state
	 * @return StateConfigurator
	 */
	function forState($state) {
		if (!isset($this->configurations[$state]))
			$this->configurations[$state] = new StateConfigHolder($state);
		return new StateConfigurator($this->configurations[$state], $this->eventUpdater);
	}

	/**
	 * Attempts to execute a transition with a trigger and optional arguments if the trigger has a condition
	 * @param string $trigger Name of the trigger to execute
	 * @param null $args User supplied arguments for the transition
	 * @return bool Was the transition succesful?
	 * @throws StateMachineException
	 */
	function fire($trigger, $args = null) {
		if (!$this->state)
			throw new StateMachineException("Initial state not defined");

		$trans = $this->tryFindHandler($this->state, $trigger);
		if (!$trans) {
			if (!empty($this->events['unhandled'])) {
				$this->fireEvent('unhandled', null, ['trigger' => $trigger, 'state' => $this->state], null);
				return false;
			} else {
				throw new StateMachineException("Trigger $trigger not found for state " . $this->state . ' or parent states');
			}
		}
		$userData = $this->resolveData($args);
		if ($trans->cond) {
			if (!$trans->evalCond($userData)) {
				return false; // exception?
			}
		}

		$eventData = $trans->asArray();
		$dest = $trans->resolveDestination($userData);
		$eventData['destination'] = $dest;
		$this->fireEvent('exit', $this->state, $eventData, $userData);
		$this->state = $dest;
		if (is_callable($this->mutator))
			call_user_func($this->mutator, $this->state);
		$this->fireEvent('transition', null, $eventData, $userData);
		$this->fireEvent('entry', $dest, $eventData, $userData);
		return true;
	}

	protected function resolveData($args) {
		if ($args != null)
			return $args;
		if (is_callable($this->dataContext)) {
			return call_user_func($this->dataContext);
		}
		return $this->dataContext;
	}

	protected function fireEvent($type, $forState, $eventData, $userData) {
		$list = $this->events[$type];
		if ($type == 'entry' || $type == 'exit') {
			if (empty($list[$forState]))
				return;
			$list = $list[$forState];
		}
		foreach ($list as $event) {
			call_user_func($event, $eventData, $userData);
		}
	}

	/**
	 * @param $state
	 * @param bool $check
	 * @throws StateMachineException
	 * @return StateConfigHolder
	 */
	protected function getConfig($state, $check = true) {
		if (empty($this->configurations[$state])) {
			if ($check)
				throw new StateMachineException("No configuration for state " . $state);
			return null;
		}
		return $this->configurations[$state];
	}

	/**
	 * Determines if a trigger can be used in the current state
	 * @param $trigger
	 * @param null $args Optional user supplied arguments
	 * @return bool
	 * @throws StateMachineException
	 */
	function canFire($trigger, $args = null) {
		$trans = $this->tryFindHandler($this->state, $trigger);
		if (!$trans)
			return false;
		$data = $this->resolveData($args);
		$res = $trans->evalCond($data);
		return $res ? true : false;
	}

	/**
	 * @param $state
	 * @param $trigger
	 * @return Transition
	 * @throws StateMachineException
	 */
	protected function tryFindHandler($state, $trigger) {
		$config = $this->getConfig($state, false);
		if (!$config) return null;
		$trans = $config->getTrigger($trigger);
		if (!$trans) {
			if ($config->parent)
				return $this->tryFindHandler($config->parent, $trigger);
			return null;
		}
		return $trans;
	}

	/**
	 * Returns an array of possible transitions from current state indexed as trigger => destination state.
	 * If there are parent state
	 * @param null $args Optional user supplied arguments
	 * @param bool $evalDynamic If dynamic transitions should be evaluated
	 * @throws StateMachineException
	 * @return array Array of possible triggers indexed as trigger => destination state
	 */
	function allowedTriggers($args = null, $evalDynamic = false) {
		$config = $this->getConfig($this->state, false);
		if (!$config)
			return false;
		$lista = $config->transitions;
		$parentConfig = $this->getParent($this->state);
		while ($parentConfig) {
			foreach ($parentConfig->transitions as $trigger => $trans) {
				if (isset($lista[$trigger])) // keep original
					continue;
				$lista[$trigger] = $trans;
			}
			$parentConfig = $this->getParent($parentConfig->parent);
		}
		$data = $this->resolveData($args);
		$result = array();
		/** @var  $trans Transition */
		foreach ($lista as $trigger => $trans) {
			if (!$trans->evalCond($data))
				continue;
			$dest = $trans->resolveDestination($data, $evalDynamic);
			$result[$trigger] = $dest ? $dest : '?';
		}
		return $result;
	}
}

class StateConfigHolder {
	var $state;
	var $transitions = array();
	var $parent = null;

	function __construct($state) {
		$this->state = $state;
	}

	function getTrigger($trigger) {
		return !empty($this->transitions[$trigger]) ?
			$this->transitions[$trigger] : null;
	}
}

class StateConfigurator {
	/** @var StateConfigHolder */
	protected $holder = null;
	protected $eventUpdater;

	function __construct($holder, $updater) {
		$this->holder = $holder;
		$this->eventUpdater = $updater;
	}

	function substateOf($parentState) {
		$this->holder->parent = $parentState;
		return $this;
	}

	/**
	 * Configures a transition in the form 'With the trigger $trigger, proceed to state $destination'
	 * @param string $trigger Trigger name
	 * @param string $destination Name of the destination state
	 * @param null|callable $condition Optional condition to evaluate if trigger can be fired
	 * @return $this
	 */
	function permit($trigger, $destination, $condition = null) {
		$transition = new Transition($this->holder->state, $destination, $trigger);
		$transition->cond = $condition;
		$this->holder->transitions[$trigger] = $transition;
		return $this;
	}

	/**
	 * Allows a transition to have the destination state resolved dynamically using a callable function
	 * @param string $trigger Trigger name
	 * @param callable $callableDestination Function that must return a viable destination state
	 * @param null|callable $condition Optional condition to evaluate if trigger can be fired
	 * @return $this
	 */
	function permitDynamic($trigger, $callableDestination, $condition = null) {
		$transition = new Transition($this->holder->state, '', $trigger);
		$transition->cond = $condition;
		$transition->dynamic = $callableDestination;
		$this->holder->transitions[$trigger] = $transition;
		return $this;
	}

	/**
	 * Sets a function to be called before a transition to this state occurs
	 * @param $callable
	 * @return $this
	 */
	function onEntry($callable) {
		if ($this->eventUpdater) {
			$func = $this->eventUpdater;
			$func('entry', $this->holder->state, $callable);
		}
		return $this;
	}

	/**
	 * Sets a function to be called after a transition to this state occurs
	 * @param $callable
	 * @return $this
	 */
	function onExit($callable) {
		if ($this->eventUpdater) {
			$func = $this->eventUpdater;
			$func('exit', $this->holder->state, $callable);
		}
		return $this;
	}

	/**
	 * @param null $args Optional user supplied arguments
	 * @param bool $evalDynamic If dynamic transitions should be evaluated
	 * @throws StateMachineException
	 * @return array Array of possible triggers for the current state (with optional destinations)
	 */
	function allowedTriggers($args = null, $evalDynamic = false) {
		$result = array();
		/** @var  $trans Transition */
		foreach ($this->holder->transitions as $trigger => $trans) {
			if (!$trans->evalCond($args))
				continue;
			$dest = $trans->resolveDestination($args, $evalDynamic);
			$result[$trigger] = $dest ? $dest : '?';
		}
		return $result;
	}
}

class Transition {
	var $source;
	var $destination;
	var $trigger;
	var $cond;
	var $dynamic;

	function __construct($source, $destination, $trigger) {
		$this->source = $source;
		$this->trigger = $trigger;
		$this->destination = $destination;
	}

	function evalCond($args = null) {
		if (!isset($this->cond))
			return true;
		$event = $this->asArray();
		if (!is_callable($this->cond))
			return $this->cond ? true : false;
		return call_user_func($this->cond, $event, $args);
	}

	function isReentry() {
		return $this->source == $this->destination;
	}

	function asArray() {
		return array(
			'source' => $this->source,
			'trigger' => $this->trigger,
			'destination' => $this->destination,
		);
	}

	function resolveDestination($args = null, $evalDynamic = true) {
		if (!$this->dynamic || !$evalDynamic)
			return $this->destination;
		if (!is_callable($this->dynamic))
			throw new StateMachineException("Dynamic state function must be a callabe for trigger " . $this->trigger);
		$dest = call_user_func($this->dynamic, $this->asArray(), $args);
		if (!$dest)
			throw new StateMachineException("Invalid dynamic state for trigger " . $this->trigger);
		return $dest;
	}
}

class StateMachineException extends \Exception {
}