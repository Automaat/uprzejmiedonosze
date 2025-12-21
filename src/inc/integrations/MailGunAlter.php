<?php

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class MailGunAlter extends MailGun {
    public function __construct() {
        parent::__construct(true);
    }
}
