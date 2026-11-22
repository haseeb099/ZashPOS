<?php

/**
 * Idempotent ensure of base-plan standalone PDQ payment type.
 **/

use App\Models\PaymentType;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up()
    {
        if ( PaymentType::query()->where( 'identifier', 'card-standalone-pdq' )->exists() ) {
            return;
        }

        $authorId = (int) ( function_exists( 'ns' ) ? ( ns()->getValidAuthor() ?: 0 ) : 0 );
        if ( $authorId < 1 ) {
            $authorId = (int) ( User::query()->value( 'id' ) ?: 1 );
        }

        $type = new PaymentType;
        $type->identifier = 'card-standalone-pdq';
        $type->label = 'Card – standalone PDQ';
        $type->active = true;
        $type->readonly = true;
        $type->author_id = $authorId;
        $type->save();
    }

    public function down()
    {
        // Keep the payment type; historical orders may reference it.
    }
};
