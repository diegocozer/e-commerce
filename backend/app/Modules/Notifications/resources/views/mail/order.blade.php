<x-mail::message>
# {{ $title }}

{{ $greeting }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($pix)
**PIX copia e cola** (válido até {{ $pix['expires_at'] }}):

<x-mail::panel>
{{ $pix['copy_paste'] }}
</x-mail::panel>
@endif

**Pedido {{ $order->number }}**

<x-mail::table>
| Item | Quantidade | Total |
|:-----|:-----------|------:|
@foreach ($items as $item)
| {{ $item['name'] }} | {{ $item['configuration'] }} | {{ $item['total'] }} |
@endforeach
</x-mail::table>

@foreach ($totals as $label => $value)
@if ($value !== null)
{{ $label }}: **{{ $value }}**<br>
@endif
@endforeach

<x-mail::button :url="$actionUrl">
Ver pedido
</x-mail::button>

Obrigado por comprar com a gente,<br>
{{ $store }}
</x-mail::message>
