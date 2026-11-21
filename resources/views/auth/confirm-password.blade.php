@extends('layouts.app')

@section('content')
    <div class="heading">
        <h1 class="h5 font-weight-bold text-uppercase mb-0" style="color: #f26522">
            {{ __('Confirm Password') }}
        </h1>
        <hr class="block-separator separator-left" style="border-top-color: #f26522">

        <p>{{ __('This is a secure area of the application. Please confirm your password before continuing.') }}</p>

        @include('errors.validation')

        <form method="POST" action="{{ route('password.confirm') }}">
            @csrf

            <div class="form-group">
                <label for="password" class="font-weight-bold label-required">{{ __('Password') }}</label>

                <div class="input-with-icon">
                    <i class="icon-lock5 input-icon" aria-hidden="true"></i>
                    <input id="password" type="password" class="form-control{{ $errors->has('password') ? ' is-invalid' : '' }}" name="password" required autocomplete="current-password" autofocus>
                </div>

                @if ($errors->has('password'))
                    <span class="invalid-feedback" role="alert">
                        <strong>{{ $errors->first('password') }}</strong>
                    </span>
                @endif
            </div>

            <button type="submit" class="btn btn-primary btn-sm btn-block">
                {{ __('Confirm') }}
            </button>
        </form>
    </div>

@endsection
