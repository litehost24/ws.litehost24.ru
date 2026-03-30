@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4>Детали сервера</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>ID:</strong> {{ $server->id }}</p>
                            <p><strong>VPN Bundle:</strong> {{ $server->vpnAccessModeLabel() }}</p>
                            @if ($server->usesNode1Api())
                                <h5>AWG / API узел</h5>
                                <p><strong>IP:</strong> {{ $server->ip1 }}</p>
                                <p><strong>API URL:</strong> {{ $server->node1_api_url }}</p>
                                <p><strong>mTLS CA:</strong> {{ $server->node1_api_ca_path }}</p>
                                <p><strong>mTLS Cert:</strong> {{ $server->node1_api_cert_path }}</p>
                                <p><strong>mTLS Key:</strong> {{ $server->node1_api_key_path }}</p>
                            @else
                                <h5>Legacy node 1</h5>
                                <p><strong>IP:</strong> {{ $server->ip1 }}</p>
                                <p><strong>Username:</strong> {{ $server->username1 }}</p>
                                <p><strong>Webbase Path:</strong> {{ $server->webwasepath1 }}</p>
                                <p><strong>URL:</strong> {{ $server->url1 }}</p>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <h5>VLESS узел</h5>
                            <p><strong>IP:</strong> {{ $server->ip2 }}</p>
                            <p><strong>Username:</strong> {{ $server->username2 }}</p>
                            <p><strong>Webbase Path:</strong> {{ $server->webwasepath2 }}</p>
                            <p><strong>URL:</strong> {{ $server->url2 }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <a href="{{ route('servers.index') }}" class="btn btn-secondary">Назад к списку</a>
                        <a href="{{ route('servers.edit', $server->id) }}" class="btn btn-primary">Редактировать</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
