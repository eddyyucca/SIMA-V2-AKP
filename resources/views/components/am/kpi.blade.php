@props(['label', 'value', 'tone' => 'neutral', 'sub' => null])
<div class="am-kpi {{ $tone }}">
    <div class="k-label">{{ $label }}</div>
    <div class="k-value">{{ $value }}</div>
    @if($sub)<div class="k-sub">{{ $sub }}</div>@endif
</div>
