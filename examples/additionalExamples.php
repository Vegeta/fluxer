<?php

// include '../src/StateMachine.php';
include_once '../vendor/autoload.php';
use Fluxer\StateMachine;


class Logger {
	static function logTransition($transition, $data) {
		// do stuff
		echo 'with ' . $transition['trigger'] . ' : ' . $transition['source'] . ' -> ' . $transition['destination'];
		echo "\n";
	}
}

function sendEmail($customer, $message) {
	echo "sending email to $customer: '" . $message . "'\n";
}

function dynamicTest() {
	echo "----- DYNAMIC TRANSITION TEST -----\n";

	// FLOW:
	// (start) -> 'submit' -> (submitted) -> 'review' --> (   reviewed   ) *-> 'approve' -> (approved)
	//                                                     |             |
	//                                                     <- *'review' -|
	// * denotes conditional

	$data['maxReviewers'] = 3;
	$data['reviewCount'] = 2;
	$database = (object)$data;

	$flow = new StateMachine();
	$flow->withDataContext($database)
		->forState('start')
		->permit('submit', 'submitted');

	$flow->forState('submitted')
		->permit('review', 'reviewed');

	$flow->forState('reviewed')
		->permitDynamic('review', function ($transition, $data) {
			if ($data->reviewCount < $data->maxReviewers) {
				return 'reviewed'; // still needs more reviewing
			}
			// the maximum number of reviews is complete, move on to the next state
			return 'reviewComplete';
		});

	$flow->forState('reviewComplete')
		->onEntry(function () {
			echo "Review process completed\n";
		});

	$flow->init('submitted');
	$flow->fire('review');
	assert($flow->getState() == 'reviewed');
	// modify data to force alternate state
	$database->reviewCount++;
	$flow->fire('review');
	assert($flow->getState() == 'reviewComplete');
}

function conditionalTest() {
	echo "----- CONDITIONAL TRANSITION TEST -----\n";

	$permissions = [
		'canSubmit' => true,
		'canReview' => false,
		'canApprove' => false,
	];

	// FLOW
	// (start) -> 'submit' -> (submitted) -> 'review' -> (reviewed) -> 'approve' -> (approved)
	//     |                    |
	//     <----- 'return' <----|
	//
	// Note that the $permissions array is passed as reference to the conditional function to simulate
	// global state being changed outside the scope of the state machine, only for demonstration purposes.
	// In real scenarios, use data context instead!

	$flow = new StateMachine();
	$flow->forState('start')
		->permit('submit', 'submitted');

	$flow->forState('submitted')
		->permit('review', 'reviewed', function () use (&$permissions) {
			return $permissions['canReview'];
		})
		->permit('return', 'start');

	$flow->forState('reviewed')
		->permit('approve', 'approved', function () use (&$permissions) {
			return $permissions['canApprove'];
		});

	$flow->init('start')
		->fire('submit');

	assert($flow->getState() === 'submitted');
	$permissions['canReview'] = false;
	assert($flow->canFire('review') === false);
	$permissions['canReview'] = true;
	assert($flow->canFire('review') === true);

	$flow->fire('review');
	assert($flow->getState() === 'reviewed');
	$allowed = $flow->allowedTriggers();
	assert(!isset($allowed['approve'])); // the approve trigger is not available due to permissions

	$permissions['canApprove'] = true;
	assert($flow->canFire('approve') === true);
	$flow->fire('approve');
	assert($flow->getState() === 'approved');
}

function conditionalWithContext() {
	$carContext = new stdClass();
	$carContext->parkingBrake = 'on';

	$flow = new StateMachine();
	$flow->withDataContext($carContext)
		->forState('engineStarted')
		->permit('drive', 'running', function ($event, $data) {
			return $data->parkingBrake == 'off';
		})
		->permit('stopEngine', 'stopped');

	$flow->init('engineStarted');
	$carContext->parkingBrake = 'on';
	assert($flow->canFire('drive') == false);
	$carContext->parkingBrake = 'off';
	assert($flow->canFire('drive') == true);
	$flow->fire('drive');
	assert($flow->getState() == 'running');
}

function checkoutExample() {
	$data = new stdClass();
	$data->customer = 'jim';

	$machine = new StateMachine();
	$machine
		->withDataContext($data)
		->onTransition(array('Logger', 'logTransition'))
		->forState('checkout')
		->permit('create', 'pending')
		->permit('confirm', 'confirmed');

	$machine->forState('pending')
		->permit('confirm', 'confirmed');

	$machine->forState('confirmed')
		->onEntry(function ($transition, $data) { // anonymous function
			// send email to customer
			sendEmail($data->customer, 'Order confirmed');
		})
		->permit('cancel', 'cancelled');

	$machine->init('checkout');
	$machine->fire('create');
	$machine->fire('confirm');
	$machine->fire('cancel');
}

conditionalTest();
dynamicTest();
conditionalWithContext();
checkoutExample();

