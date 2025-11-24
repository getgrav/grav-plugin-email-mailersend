<?php return array(
    'root' => array(
        'name' => 'getgrav/email-mailersend',
        'pretty_version' => 'dev-develop',
        'version' => 'dev-develop',
        'reference' => '5ca13ffa4fc3ad6c6899b5709c57ea4c64f0ae36',
        'type' => 'grav-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'getgrav/email-mailersend' => array(
            'pretty_version' => 'dev-develop',
            'version' => 'dev-develop',
            'reference' => '5ca13ffa4fc3ad6c6899b5709c57ea4c64f0ae36',
            'type' => 'grav-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'psr/event-dispatcher' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'rhukster/mailersend-mailer' => array(
            'pretty_version' => '5.4.2',
            'version' => '5.4.2.0',
            'reference' => '6b6323f92be28c70daeb722de8c369b22d180d52',
            'type' => 'rhukster-mailer-bridge',
            'install_path' => __DIR__ . '/../rhukster/mailersend-mailer',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'symfony/mailer' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
    ),
);
