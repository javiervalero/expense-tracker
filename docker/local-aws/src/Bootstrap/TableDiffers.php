<?php

declare(strict_types=1);

namespace LocalAws\Bootstrap;

/**
 * The table that already exists is not the one the schema file describes. The bootstrap never deletes a table, so the
 * developer decides: the message names the fields and the way out.
 */
final class TableDiffers extends \RuntimeException {}
