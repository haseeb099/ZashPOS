<?php

namespace App\Http\Requests;

use App\Models\OrderPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class OrderPaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'identifier' => 'required',
            'value' => 'required|numeric',
            'reference' => 'nullable|string|max:191',
        ];
    }

    public function withValidator( Validator $validator ): void
    {
        $validator->after( function ( Validator $validator ): void {
            if ( $this->input( 'identifier' ) === OrderPayment::PAYMENT_CARD_INTEGRATED ) {
                $validator->errors()->add(
                    'identifier',
                    __( 'Integrated PDQ payments can only be recorded by the terminal settlement service.' )
                );
            }
        } );
    }
}
