<?php
$dt = \DateTime::createFromFormat('Y:m:d H:i:s', "1800:01:01 00:00:00");

var_dump($dt->getTimestamp());