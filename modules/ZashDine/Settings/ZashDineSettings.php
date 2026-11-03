<?php

namespace Modules\ZashDine\Settings;

use App\Classes\FormInput;
use App\Classes\SettingForm;
use App\Services\Helper;
use App\Services\SettingsPage;

class ZashDineSettings extends SettingsPage
{
    const IDENTIFIER = 'zash-dine';

    const AUTOLOAD = true;

    public function __construct()
    {
        $this->form = SettingForm::form(
            title: __m( 'ZashDine Settings', 'ZashDine' ),
            description: __m( 'Configure restaurant dine-in defaults for ZashDine.', 'ZashDine' ),
            tabs: SettingForm::tabs(
                SettingForm::tab(
                    label: __m( 'General', 'ZashDine' ),
                    identifier: 'general',
                    fields: SettingForm::fields(
                        FormInput::switch(
                            label: __m( 'Enable ZashDine Features', 'ZashDine' ),
                            name: 'zash_dine_enabled',
                            options: Helper::kvToJsOptions( [
                                'no' => __m( 'No', 'ZashDine' ),
                                'yes' => __m( 'Yes', 'ZashDine' ),
                            ] ),
                            value: ns()->option->get( 'zash_dine_enabled', 'yes' ),
                            description: __m( 'Turn restaurant dine-in features on or off for this store.', 'ZashDine' ),
                        ),
                        FormInput::textarea(
                            label: __m( 'Default Kitchen Ticket Note', 'ZashDine' ),
                            name: 'zash_dine_default_ticket_note',
                            value: ns()->option->get( 'zash_dine_default_ticket_note', '' ),
                            description: __m( 'Optional note printed on new kitchen tickets when waiters do not add one.', 'ZashDine' ),
                        ),
                    )
                )
            )
        );
    }
}
