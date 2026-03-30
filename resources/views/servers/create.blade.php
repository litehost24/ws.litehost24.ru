@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4>Добавить новый сервер</h4>
                </div>
                <div class="card-body">
                    <form action="{{ route('servers.store') }}" method="POST">
                        @csrf
                        <div class="alert alert-info">
                            В записи сейчас два реальных узла: <strong>AWG сервер</strong> и <strong>VLESS сервер</strong>. Старые поля legacy node1 нужны только для совместимости со старыми схемами.
                        </div>

                        <div class="card mb-4">
                            <div class="card-header">
                                <strong>AWG сервер</strong>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="vpn_access_mode">Тип новых подписок</label>
                                            <select name="vpn_access_mode" id="vpn_access_mode" class="form-control">
                                                @foreach(\App\Models\Server::vpnAccessModeOptions() as $modeValue => $modeLabel)
                                                    <option value="{{ $modeValue }}" {{ old('vpn_access_mode', \App\Models\Server::VPN_ACCESS_WHITE_IP) === $modeValue ? 'selected' : '' }}>
                                                        {{ $modeLabel }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <small class="form-text text-muted">VLESS остаётся общим запасным протоколом. Здесь определяется только AWG-сервер.</small>
                                            @error('vpn_access_mode')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="ip1">IP AWG сервера</label>
                                            <input type="text" name="ip1" class="form-control" id="ip1" value="{{ old('ip1') }}">
                                            @error('ip1')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="node1_api_enabled">Управление через API</label>
                                            <select name="node1_api_enabled" id="node1_api_enabled" class="form-control">
                                                <option value="0" {{ old('node1_api_enabled', '0') == '0' ? 'selected' : '' }}>Нет</option>
                                                <option value="1" {{ old('node1_api_enabled') == '1' ? 'selected' : '' }}>Да</option>
                                            </select>
                                            @error('node1_api_enabled')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="node1_api_url">API URL AWG сервера</label>
                                            <input type="url" name="node1_api_url" class="form-control" id="node1_api_url" value="{{ old('node1_api_url') }}">
                                            @error('node1_api_url')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group mb-3">
                                            <label for="node1_api_ca_path">mTLS CA</label>
                                            <input type="text" name="node1_api_ca_path" class="form-control" id="node1_api_ca_path" value="{{ old('node1_api_ca_path') }}">
                                            @error('node1_api_ca_path')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group mb-3">
                                            <label for="node1_api_cert_path">mTLS Cert</label>
                                            <input type="text" name="node1_api_cert_path" class="form-control" id="node1_api_cert_path" value="{{ old('node1_api_cert_path') }}">
                                            @error('node1_api_cert_path')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group mb-3">
                                            <label for="node1_api_key_path">mTLS Key</label>
                                            <input type="text" name="node1_api_key_path" class="form-control" id="node1_api_key_path" value="{{ old('node1_api_key_path') }}">
                                            @error('node1_api_key_path')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>

                                <details class="mt-2">
                                    <summary class="text-muted">Legacy node1 поля</summary>
                                    <div class="mt-3 border rounded p-3 bg-light">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label for="username1">Legacy Username 1</label>
                                                    <input type="text" name="username1" class="form-control" id="username1" value="{{ old('username1') }}">
                                                    @error('username1')
                                                        <div class="text-danger">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label for="password1">Legacy Password 1</label>
                                                    <input type="password" name="password1" class="form-control" id="password1">
                                                    @error('password1')
                                                        <div class="text-danger">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label for="webwasepath1">Legacy Webbase Path 1</label>
                                                    <input type="text" name="webwasepath1" class="form-control" id="webwasepath1" value="{{ old('webwasepath1') }}">
                                                    @error('webwasepath1')
                                                        <div class="text-danger">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group mb-3">
                                                    <label for="url1">Legacy URL 1</label>
                                                    <input type="url" name="url1" class="form-control" id="url1" value="{{ old('url1') }}">
                                                    @error('url1')
                                                        <div class="text-danger">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header">
                                <strong>VLESS сервер</strong>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="ip2">IP VLESS сервера</label>
                                            <input type="text" name="ip2" class="form-control" id="ip2" value="{{ old('ip2') }}">
                                            @error('ip2')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="url2">URL панели / API VLESS</label>
                                            <input type="url" name="url2" class="form-control" id="url2" value="{{ old('url2') }}">
                                            @error('url2')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="username2">Логин VLESS</label>
                                            <input type="text" name="username2" class="form-control" id="username2" value="{{ old('username2') }}">
                                            @error('username2')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="password2">Пароль VLESS</label>
                                            <input type="password" name="password2" class="form-control" id="password2">
                                            @error('password2')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label for="webwasepath2">Webbase Path VLESS</label>
                                            <input type="text" name="webwasepath2" class="form-control" id="webwasepath2" value="{{ old('webwasepath2') }}">
                                            @error('webwasepath2')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mt-0">
                                            <label for="vless_inbound_id">VLESS Inbound ID</label>
                                            <input type="number" name="vless_inbound_id" class="form-control" id="vless_inbound_id" value="{{ old('vless_inbound_id') }}" min="1">
                                            @error('vless_inbound_id')
                                                <div class="text-danger">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Сохранить</button>
                            <a href="{{ route('servers.index') }}" class="btn btn-secondary">Отмена</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
