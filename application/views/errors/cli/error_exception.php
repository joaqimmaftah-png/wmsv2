<?php
defined('BASEPATH') OR exit('No direct script access allowed');
echo "\nAn uncaught Exception was encountered\nType:     " . get_class($exception) . "\nMessage:  {$message}\nFilename: " . $exception->getFile() . "\nLine:     " . $exception->getLine() . "\n\n";
