<div
    role="alert"
    style="
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.75rem 1.25rem;
        background-color: #fef3c7;
        border-top: 2px solid #f59e0b;
        color: #92400e;
        font-size: 0.875rem;
    "
>
    <span>
        {{ __('filament-impersonation::messages.banner_text', [
            'impersonator' => $impersonatorName ?? __('filament-impersonation::messages.unknown'),
            'impersonated' => $impersonatedName ?? __('filament-impersonation::messages.unknown'),
        ]) }}
    </span>

    @if (isset($leaveUrl))
        <a
            href="{{ $leaveUrl }}"
            style="
                font-weight: 600;
                text-decoration: underline;
                white-space: nowrap;
                color: inherit;
            "
        >
            {{ __('filament-impersonation::messages.leave_impersonation') }}
        </a>
    @endif
</div>
