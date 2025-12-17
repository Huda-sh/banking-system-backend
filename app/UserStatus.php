<?php

namespace App;

enum UserStatus : string
{
    case ACTIVE = 'active';
    case BLOCKED = 'blocked';
    case CLOSED = 'closed';
}
