@extends('layouts.base')

@section('content')

    <div class="container small py-xl">

        <main class="card content-wrap auto-height">
            <div id="main-content" class="body">
                <h3>@icon('lock') {{ trans('errors.permission') }}</h3>
                <h5 class="mb-m">{{ $exception->getMessage() ?: trans('errors.permission') }}</h5>
                <p><a href="{{ url('/') }}" class="button outline">{{ trans('errors.return_home') }}</a></p>
            </div>
        </main>

    </div>

@stop
