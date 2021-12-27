<?php

namespace Rockett\Support;

use DateTime;

final class ParseTimestamp
{
  public static function fromInt(int $timestamp): DateTime
  {
    return new DateTime(date('c', $timestamp));
  }
}
