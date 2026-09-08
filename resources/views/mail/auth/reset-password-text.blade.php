{{ $greeting }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach
{{ $actionText }}: {!! $actionUrl !!}

@foreach ($outroLines as $line)
{{ $line }}

@endforeach
{{ $salutation }}

{!! $fallback !!}

{!! $actionUrl !!}
