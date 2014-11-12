<?php

include_once '../src/StateMachine.php';
use Fluxer\StateMachine;

function printTransition($event) {
	echo "> Transition: with '" . $event['trigger'] . "' from '" . $event['source'] . "' -> '" . $event['destination'] . "'\n";
}

function telephone() {
	echo "\n------ TELEPHONE EXAMPLE -----\n";
	$fluxer = new StateMachine();
	$fluxer->forState('offHook')
		->permit('callDialed', 'ringing');

	$fluxer->forState('ringing')
		->permit('hungUp', 'offHook')
		->permit('callConnected', 'connected');

	$fluxer->forState('connected')
		->permit('leftMessage', 'offHook')
		->permit('hungUp', 'offHook')
		->permit('placedOnHold', 'onHold');

	$fluxer->forState('onHold')
		->substateOf('connected')
		->permit('takenOffHold', 'connected')
		->permit('hungUp', 'offHook')
		->permit('hurlPhoneToWall', 'DESTROYED');

	$fluxer->onTransition(function ($event) {
		printTransition($event);
	})->init('offHook');

	$fluxer->fire('callDialed');
	assert($fluxer->getState() == 'ringing');
	$fluxer->fire('callConnected');
	assert($fluxer->getState() == 'connected');
	$fluxer->fire('placedOnHold');
	assert($fluxer->getState() == 'onHold');

	assert($fluxer->isInState('connected')); // onHold is a substate of connected

	$allowed = $fluxer->allowedTriggers();
	assert(isset($allowed['leftMessage'])); // trigger for parent state is available in substate as well

	$fluxer->fire('takenOffHold');
	assert($fluxer->getState() == 'connected');
	$fluxer->fire('hungUp');
	assert($fluxer->getState() == 'offHook');
}

function bugTracker() {
	echo "\n------ BUG TRACKER EXAMPLE -----\n";

	$bug = new stdClass();
	$bug->user = '';

	$fluxer = new StateMachine();
	$condition = function ($event, $name) {
		if ($name == 'admin') {
			echo "Can't assign to admin!\n";
			echo "> NO transition\n";
			return false;
		}
		return true;
	};

	$fluxer->forState('open')
		->permit('assign', 'assigned', $condition);

	$fluxer->forState('assigned')
		->permit('assign', 'assigned', $condition)// reentry
		->permit('close', 'closed')
		->permit('defer', 'deferred')
		->onEntry(function ($event, $user) use ($bug) {
			if ($event['source'] == 'assigned') {
				echo "bug re-assigned to $user\n";
			} else {
				echo "bug assigned to $user\n";
			}
			$bug->user = $user;
		})
		->onExit(function ($event) use ($bug) {
			if ($event['destination'] != 'assigned') {
				echo "user $bug->user released\n";
				$bug->user = '';
			}
		});

	$fluxer->forState('deferred')
		->permit('assign', 'assigned');

	$fluxer->onTransition(function ($event) use ($bug) {
		printTransition($event);
		echo "Current user: $bug->user\n";
		if ($event['destination'] == 'closed')
			echo "Bug closed\n";
		if ($event['destination'] == 'deferred')
			echo "Bug deferred\n";
	});

	$fluxer->init('open');
	$fluxer->fire('assign', 'joe');
	$fluxer->fire('assign', 'admin');
	$fluxer->fire('defer');
	$fluxer->fire('assign', 'mike');
	$fluxer->fire('close');
}

function onOff() {
	echo "\n------ ON/OFF SWITCH EXAMPLE -----\n";

	$flujo = new StateMachine();
	$flujo->forState('on')->permit(' ', 'off');
	$flujo->forState('off')->permit(' ', 'on');

	$flujo->onUnhandledTrigger(function ($info) {
		echo 'Trigger ' . $info['trigger'] . " not found\n";
	})->onTransition(function ($event) {
		printTransition($event);
	})->onEntryFor('on', function ($info, $data) {
		// oh look, the light went on!
	});

	$flujo->init('off');

	$flujo->fire(' ');
	$flujo->fire(' ');
	$flujo->fire(' ');
	$flujo->fire('00');

	var_dump($flujo->allowedTriggers());
}

onOff();
bugTracker();
telephone();
