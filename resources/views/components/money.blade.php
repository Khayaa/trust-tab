@props(['amount'])

{{--
    Amounts are decimal strings from the database. Formatting is centralised so
    the display currency can never drift into a MoMo request by accident.
--}}
<span {{ $attributes->merge(['class' => 'tabular-nums']) }}>{{
    Number::currency(
        (float) $amount,
        config('trusttab.money.currency'),
        config('trusttab.money.locale'),
    )
}}</span>
