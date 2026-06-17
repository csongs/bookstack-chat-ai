@extends('layouts.simple')

@section('body')
<div class="container medium">

    <div class="py-m">
        @include('settings/parts/navbar', ['selected' => 'ai-chat'])
    </div>

    @if(session('status') === 'saved')
        <div class="notification success mb-m">設定已儲存。</div>
    @endif

    <form method="POST" action="{{ url('/ai-chat/settings') }}">
        @csrf

        {{-- Driver 選擇 --}}
        <div class="card content-wrap auto-height mb-m">
            <h1 class="list-heading">@icon('link') AI 供應商</h1>
            <p class="text-muted mt-s">
                選擇使用的 AI 供應商。各供應商的 API 金鑰與 endpoint 請設定於伺服器環境變數。
            </p>

            <div class="setting-list">
                <div class="setting-list-item">
                    <label class="setting-list-label">供應商</label>
                    <select name="provider" id="ai-chat-provider" class="setting-list-input" style="max-width:320px">
                        @foreach($drivers as $key => $driver)
                            <option value="{{ $key }}" {{ $provider === $key ? 'selected' : '' }}>
                                {{ $driver['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- 各 Driver 的環境變數說明 --}}
                @foreach($drivers as $key => $driver)
                <div class="ai-driver-envvars" data-driver="{{ $key }}"
                     style="{{ $provider === $key ? '' : 'display:none' }}">
                    <div class="setting-list-item">
                        <label class="setting-list-label">所需環境變數</label>
                        <div class="text-muted" style="font-family:monospace;font-size:0.875rem;line-height:2">
                            @foreach($driver['envVars'] as $var => $desc)
                                <div><strong>{{ $var }}</strong> — {{ $desc }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        {{-- System Prompt --}}
        <div class="card content-wrap auto-height mb-m">
            <h2 class="list-heading">@icon('edit') System Prompt</h2>

            <div class="setting-list">
                <div class="setting-list-item">
                    <textarea name="system_prompt" class="setting-list-input" rows="5"
                              placeholder="You are a helpful assistant.">{{ $systemPrompt }}</textarea>
                </div>
            </div>
        </div>

        {{-- 存取權限 --}}
        <div class="card content-wrap auto-height mb-m">
            <h2 class="list-heading">@icon('user') 存取權限</h2>
            <p class="text-muted mt-s">
                設定哪些角色可以使用 Ask AI。未勾選任何角色則對所有人隱藏。<br>
                擁有 <strong>settings-manage</strong> 權限的管理員永遠可以使用。
            </p>

            <div class="setting-list mt-m">
                @forelse($allRoles as $role)
                    <div class="setting-list-item">
                        <label class="flex-container-row gap-m items-center toggle-switch-label">
                            <input type="checkbox" name="roles[]"
                                   value="{{ $role->id }}"
                                   {{ in_array($role->id, $savedRoles) ? 'checked' : '' }}>
                            <div><strong>{{ $role->display_name }}</strong></div>
                        </label>
                    </div>
                @empty
                    <p class="text-muted italic">目前沒有任何角色。</p>
                @endforelse
            </div>
        </div>

        <div class="mb-xl">
            <button type="submit" class="button">儲存設定</button>
        </div>
    </form>

</div>
@stop

@push('body-end')
<script>
document.getElementById('ai-chat-provider').addEventListener('change', function () {
    document.querySelectorAll('.ai-driver-envvars').forEach(el => {
        el.style.display = el.dataset.driver === this.value ? '' : 'none';
    });
});
</script>
@endpush
