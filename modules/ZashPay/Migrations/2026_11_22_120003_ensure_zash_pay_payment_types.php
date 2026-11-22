<?php

/**
 * Ensure integrated PDQ payment type exists (inactive until add-on enabled).
 */

use App\Models\PaymentType;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if ( PaymentType::query()->where( 'identifier', 'card-integrated-pdq' )->exists() ) {
            return;
        }

        $authorId = (int) ( function_exists( 'ns' ) ? ( ns()->getValidAuthor() ?: 0 ) : 0 );
        if ( $authorId < 1 ) {
            $authorId = (int) ( User::query()->value( 'id' ) ?: 1 );
        }

        $type = new PaymentType;
        $type->identifier = 'card-integrated-pdq';
        $type->label = 'Card – integrated PDQ';
        $type->active = false;
        $type->readonly = true;
        $type->author_id = $authorId;
        $type->save();
    }

    public function down(): void
    {
        // Keep type for historical payments.
    }
};
