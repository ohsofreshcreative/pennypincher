<input type="hidden" name="{{ \App\PennyPincher\Http\Middleware\VerifyNonce::FIELD }}" value="{{ wp_create_nonce(\App\PennyPincher\Http\Middleware\VerifyNonce::ACTION) }}">
