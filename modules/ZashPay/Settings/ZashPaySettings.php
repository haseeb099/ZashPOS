<?php

namespace Modules\ZashPay\Settings;

use App\Classes\FormInput;
use App\Classes\SettingForm;
use App\Services\Helper;
use App\Services\SettingsPage;
use Modules\ZashPay\Services\ZashPayAddonGate;

class ZashPaySettings extends SettingsPage
{
    const IDENTIFIER = 'zash-pay';

    const AUTOLOAD = true;

    public function __construct()
    {
        $this->form = SettingForm::form(
            title: __m( 'ZashPay Settings', 'ZashPay' ),
            description: __m( 'Optional integrated PDQ add-on. Simulator only until a real terminal provider is connected.', 'ZashPay' ),
            tabs: SettingForm::tabs(
                SettingForm::tab(
                    label: __m( 'Integrated PDQ', 'ZashPay' ),
                    identifier: 'integrated',
                    fields: SettingForm::fields(
                        FormInput::switch(
                            label: __m( 'Enable integrated PDQ add-on', 'ZashPay' ),
                            name: ZashPayAddonGate::OPTION_ENABLED,
                            options: Helper::kvToJsOptions( [
                                'no' => __m( 'No', 'ZashPay' ),
                                'yes' => __m( 'Yes', 'ZashPay' ),
                            ] ),
                            value: ns()->option->get( ZashPayAddonGate::OPTION_ENABLED, 'no' ),
                            description: __m( 'When off, integrated terminal APIs return 403 and the card-integrated-pdq payment type stays hidden. Default is off.', 'ZashPay' ),
                        ),
                    )
                )
            )
        );
    }
}
