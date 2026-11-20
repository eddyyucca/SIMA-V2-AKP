@props(['value' => 0, 'max' => 100, 'tone' => ''])
@php $p = $max > 0 ? max(0, min(100, round($value / $max * 100, 1))) : 0; @endphp
<div class="am-bar {{ $tone }}"><span style="width: {{ $p }}%"></span></div>
