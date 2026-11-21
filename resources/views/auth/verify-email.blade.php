@extends('layouts.app')

@section('content')
    <div class="heading">
        <h1 class="h5 font-weight-bold text-uppercase mb-0" style="color: #f26522">
            {{ __('Verify Your Email Address') }}
        </h1>
        <hr class="block-separator separator-left" style="border-top-color: #f26522">
        @if (session('status') == 'verification-link-sent')
            <div class="alert alert-success" role="alert">
                {{ __('A fresh verification link has been sent to your email address.') }}
            </div>
        @endif

        <p>{{ __('Before proceeding, please check your email for a verification link.') }}</p>

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <button type="submit" class="btn btn-primary btn-sm btn-block">
                {{ __('Resend Verification Email') }}
            </button>
        </form>

        <hr>
        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf

            <button type="submit" class="btn btn-link">{{ __('Logout') }}</button>
        </form>
    </div>

@endsection
