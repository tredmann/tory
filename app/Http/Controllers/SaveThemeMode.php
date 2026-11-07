<?php

namespace App\Http\Controllers;

use Filament\Enums\ThemeMode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class SaveThemeMode extends Controller
{
    /**
     * Save the theme chosen in the user menu on the account of the logged-in user.
     */
    public function __invoke(Request $request): Response
    {
        $request->validate(['theme' => ['required', Rule::enum(ThemeMode::class)]]);

        $request->user()?->forceFill(['theme_mode' => $request->enum('theme', ThemeMode::class)])->save();

        return response()->noContent();
    }
}
