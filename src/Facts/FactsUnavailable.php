<?php

namespace Shirahcan\GrowthClient\Facts;

use RuntimeException;

/**
 * A figure cannot be read right now. The message is a plain sentence the service records on the
 * watched source and shows an admin ("Express Entry rounds have not been loaded yet."), so write it
 * for a person, never as an exception dump.
 */
class FactsUnavailable extends RuntimeException {}
