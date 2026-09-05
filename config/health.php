<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public self-registration
    |--------------------------------------------------------------------------
    |
    | The Healthcare ERP panel ships to production before it is offered for
    | sale, so the code can be exercised and proven on a real server while the
    | product is still pre-pilot. Until a pilot organisation is deliberately
    | onboarded, an ordinary visitor must not be able to create a healthcare
    | company for themselves.
    |
    | Closing this is a CONFIG decision, not a code one: opening the door for
    | the pilot is then a single environment value on the live server, with no
    | deploy and nothing to remember to revert.
    |
    | Closed by default on purpose — a new or rebuilt server must come up shut,
    | never open because someone forgot to set the variable.
    |
    */

    'registration_open' => env('HEALTH_REGISTRATION_OPEN', false),

];
