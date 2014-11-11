# fluxer

A simple, yet flexible finite state machine for PHP. This library is heavily inspired by the C# Stateless (https://github.com/nblumhardt/stateless) project.
Create state machines and lightweight state machine-based workflows directly in PHP code:

```php

require 'vendor/autoload.php';
use Fluxer\StateMachine;

$phone = new StateMachine('offHook');
$phone->forState('offHook')
	->permit('callDialed', 'ringing');

$phone->forState('ringing')
	->permit('hungUp', 'offHook')
	->permit('callConnected', 'connected');

$phone->forState('connected')
	->onEntry(function($event) { startTimer();	})
	->onExit(function($event) { stopTimer(); })
	->permit('leftMessage', 'offHook')
	->permit('hungUp', 'offHook')
	->permit('placedOnHold', 'onHold');

$phone->forState('onHold')
	->permit('takenOffHold', 'connected')
	->permit('hungUp', 'offHook');

// execute workflow
$phone->fire('callDialed');
echo $phone->getState(); // ringing
```

## Requirements

This library requires PHP 5.3.2+. The root namespace for the library is `Fluxer`.

## Installation (via composer)

```js
{
    "require": {
        "vegeta/fluxer": "dev-master"
    }
}
```

Including using the Composer autoloader.

```php
require 'vendor/autoload.php';
use Fluxer\StateMachine;

$stateMachine = new StateMachine();
```

## Features

* Fluent API for configuration
* Initial support for hierarchical states 
* Supports states and triggers using either strings or scalar constants
* Entry/Exit events for states
* Conditional transitions using variables or closures (Guard clauses)
* List of supported transitions (trigger -> destination) for any given state 

## Basic Usage

### Configuration

Create an object of type StateMachine, use the forState($state) function to configure a state and
use the permit($trigger, $newState) function to configure transitions. 

```php
$machine = new StateMachine();
$machine->forState('checkout')
	->permit('create', 'pending')
	->permit('confirm', 'confirmed');

$machine->forState('pending')
	->permit('confirm', 'confirmed');

$machine->forState('confirmed')
	->permit('cancel', 'cancelled');
```

NOTE: If constants are used as states or triggers, make sure they have a value that does not resolve to php's empty (0, false, null, '').
Sticking to strings (literals or constants) is usually the best option.

### Running the state machine

Once the states and transitions have been configured, set the initial state and fire triggers to change states.

```php
$machine->init('checkout');
$machine->fire('create');
$machine->fire('confirm');
$machine->fire('cancel');

echo $machine->getState(); // cancelled
```

You can check at any time if a trigger can be fired and the complete list of available triggers for the current 
state (introspection).

```php
$machine->canFire('checkout'); // true or false
$allowed = $machine->allowedTriggers(); // associative array: trigger => destination state
```

### Invalid trigger control

If an invalid trigger is called with the fire() method, an exception of type StateMachineException will be raised by default.
This behaviour can be changed with the onUnhandledTrigger() method that accepts a callback that will be executed
if an invalid trigger is fired. This function has only one argument which is an array with the current 'state' and
the attempted 'trigger'.

## Events and callbacks

The state machine allows for callback functions to be executed before a state is reached (onEntry), on every succesful 
transition (onTransition) and after leaving the state (onExit).

An event is defined as a callback function with the following signature: function ($transition, $userData) {},
where $transition is an array that contains the current transition information ('source', 'trigger', 'destination') and 
$userData is any user defined value passed to the event, being the current data context or a custom parameter used in the call
to fire(), canFire() or allowedTriggers().

Callbacks can be anything that qualifies as a 'callable', being an anonymous function or a reference to a method
in the form array(object|class, method) as it is usual in php.

```php
class Logger {
	static function logTransition($transition, $data) {
		// do stuff
		echo 'with ' . $transition['trigger'] . ' : ' . $transition['source'] 
			. ' -> ' . $transition['destination'];
		echo "\n";
	}
}

$machine->onTransition(array('Logger', 'logTransition')) // using call to static function
	->forState('checkout')
	->permit('create', 'pending')
	->permit('confirm', 'confirmed');

$machine->forState('confirmed')
   	->onEntry(function($transition, $data) { // anonymous function
   		// send email to customer
   		sendEmail($data->customer, 'Order confirmed');
   	})
   	->permit('cancel', 'cancelled');
   ...
```

Additionally, you can define a function to be called every time the state is changed using the setStateMutator() method.
This function only receives the new state as a single argument.

## Hierarchical States

In the following example, the state 'onHold' is a substate of 'connected' which means that all the triggers for 
'connected' that are not in 'onHold' will be available. To check if the current state belongs to a parent state
use the isInState() function which will check not only the current state but also the parents (if any).

```php
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

$fluxer->fire('callDialed');
$fluxer->fire('callConnected');
$fluxer->fire('placedOnHold');
assert( $fluxer->isInState('connected') ); // onHold is a substate of connected
$allowed = $fluxer->allowedTriggers();
assert( isset($allowed['leftMessage']) ); // trigger for parent state is available in substate as well
```

## Using external data in the state machine

You can use external data in the state machine with either a data context or passing a parameter directly to execution 
functions such as fire().

A data context can be an array, object or a function that returns data dynamically.

```php
$machine = new StateMachine();
// using an anonymous function
$machine->withDataContext(function() {
	return array('number'=>5, 'string'=>'hello');
});

// using an object
$user = new User();
$user->username = 'joe';
$user->role = 'salesman';

$machine->withDataContext($user);

// passing parameter directly to call
$machine->fire('checkout', $user);
$machine->canFire('cancel', $user);
```

When passing a parameter directly into fire(), canFire() or allowedTriggers(), this value overrides the current
data context only for that particular call. It is recommended to use a data context whenever possible.

## Conditional transitions

A transition can be configured so that it fires only when a condition is met using the third parameter for the permit()
functions to define either a variable or callable that returns true or false.

In the following example (simple car workflow), the trigger 'drive' for the state 'engineStarted' depends on a condition
that reads the value of the parkingBrake variable in a data context, in this case, a simple object.

```php
// create an object to act as external data for the state machine
$car = new stdClass();
$car->parkingBrake = 'on';

$flow = new StateMachine();
$flow->withDataContext($car)
	->forState('engineStarted')
	->permit('drive', 'running', function ($event, $data) {
		// allow to drive only if the parking brake is off
		return $data->parkingBrake == 'off';
	})
	->permit('stopEngine', 'stopped');

$flow->init('engineStarted');
$car->parkingBrake = 'on';
assert( $flow->canFire('drive') == false );
$car->parkingBrake = 'off';
assert( $flow->canFire('drive') == true );
$flow->fire('drive');
assert( $flow->getState() == 'running' );
```

## Dynamic state resolution

Sometimes, the destination state of a transition needs to be determined at runtime through queries or calculations.
If this is the case, a transition can be configured using a function that returns the actual destination state using
the permitDynamic() call. The function has the same signature as the standard events and must return a valid state. 

In the following example, after being submitted, a form must be reviewed several times before moving further in the
process. The maximum number of times is resolved externally.

```php
...

$flow->forState('submitted')
	->permit('review', 'reviewed');

$flow->forState('reviewed')
	->permitDynamic('review', function($transition, $data) {
		if($data['reviewCount'] < $data['maxReviewers']) {
			return 'reviewed'; // still need more reviewing 
		}
		// the maximum number of reviews is complete, move on to the next state
		return 'reviewComplete';
	});
	
...

// these values could come from a database
$data['maxReviewers'] = 3;
$data['reviewCount'] = 2;

$flow->init('submitted');
$flow->fire('review', $data);
assert( $flow->getState() == 'reviewed' );
$data['reviewCount']++;
$flow->fire('review', $data);
assert( $flow->getState() == 'reviewComplete' );
```

When using introspection with allowedTriggers() to get the list of possible transitions, the destination state
for dynamic transitions will show as '?', unless the argument 'evalDynamic' is set to true in which case, all
dynamic transitions will be evaluated using the data context or supplied arguments.

if the function does not return a valid state, a StateMachineException exception is raised.

### Examples

Sample state machines and workflows can be found inside the /examples folder 

### TODO

* Better control over reentrant transitions

##License##

Copyright (c) 2014 [Manolo Gomez](http://github.com/vegeta)

Released under the MIT License.
