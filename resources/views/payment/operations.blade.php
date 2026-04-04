<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <div class="p-6 lg:p-8 bg-white border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">&#1055;&#1083;&#1072;&#1090;&#1077;&#1078;&#1080; &#1080; &#1089;&#1087;&#1080;&#1089;&#1072;&#1085;&#1080;&#1103;</h3>

                    <div class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
                            <div class="text-xs uppercase text-gray-500">&#1042;&#1089;&#1077;&#1075;&#1086; &#1087;&#1086;&#1089;&#1090;&#1091;&#1087;&#1083;&#1077;&#1085;&#1080;&#1081;</div>
                            <div class="text-base font-semibold text-gray-900">{{ number_format(($operationsSummary['total_payments'] ?? 0) / 100, 2, '.', ' ') }} &#8381;</div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
                            <div class="text-xs uppercase text-gray-500">&#1042;&#1089;&#1077;&#1075;&#1086; &#1089;&#1087;&#1080;&#1089;&#1072;&#1085;&#1080;&#1081;</div>
                            <div class="text-base font-semibold text-gray-900">{{ number_format(($operationsSummary['total_charges'] ?? 0) / 100, 2, '.', ' ') }} &#8381;</div>
                        </div>
                        <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
                            <div class="text-xs uppercase text-gray-500">&#1058;&#1077;&#1082;&#1091;&#1097;&#1080;&#1081; &#1073;&#1072;&#1083;&#1072;&#1085;&#1089;</div>
                            <div class="text-base font-semibold text-gray-900">{{ number_format(($operationsSummary['balance'] ?? 0) / 100, 2, '.', ' ') }} &#8381;</div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <h4 class="text-sm font-semibold text-gray-900">&#1055;&#1083;&#1072;&#1090;&#1077;&#1078;&#1080; (&#1091;&#1089;&#1087;&#1077;&#1096;&#1085;&#1099;&#1077;)</h4>
                        <div class="mt-2 max-h-56 overflow-auto rounded-md border border-gray-200">
                            <table class="min-w-full text-xs">
                                <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-2 py-2 text-left">&#1044;&#1072;&#1090;&#1072;</th>
                                    <th class="px-2 py-2 text-left">&#1057;&#1091;&#1084;&#1084;&#1072;</th>
                                    <th class="px-2 py-2 text-left">Order ID</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($paymentsHistory as $payment)
                                    <tr class="{{ $loop->even ? 'bg-gray-50' : '' }}">
                                        <td class="px-2 py-2 whitespace-nowrap">{{ optional($payment->created_at)->format('Y-m-d H:i') }}</td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ number_format(((int)$payment->amount) / 100, 2, '.', ' ') }} &#8381;</td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ $payment->order_name }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="px-2 py-2 text-gray-500" colspan="3">&#1055;&#1083;&#1072;&#1090;&#1077;&#1078;&#1077;&#1081; &#1087;&#1086;&#1082;&#1072; &#1085;&#1077;&#1090;</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-6">
                        <h4 class="text-sm font-semibold text-gray-900">Списания по подпискам</h4>
                        <div class="mt-2 max-h-64 overflow-auto rounded-md border border-gray-200">
                            <table class="min-w-full text-xs">
                                <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-2 py-2 text-left">&#1044;&#1072;&#1090;&#1072;</th>
                                    <th class="px-2 py-2 text-left">&#1054;&#1082;&#1086;&#1085;&#1095;&#1072;&#1085;&#1080;&#1077;</th>
                                    <th class="px-2 py-2 text-left">&#1055;&#1086;&#1076;&#1087;&#1080;&#1089;&#1082;&#1072;</th>
                                    <th class="px-2 py-2 text-left">&#1057;&#1091;&#1084;&#1084;&#1072;</th>
                                    <th class="px-2 py-2 text-left">&#1044;&#1077;&#1081;&#1089;&#1090;&#1074;&#1080;&#1077;</th>
                                    <th class="px-2 py-2 text-left">&#8635;</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($chargesHistory as $charge)
                                    @php
                                        $chargePlanLabel = $charge->vpnPlanLabel();
                                    @endphp
                                    <tr class="{{ $loop->even ? 'bg-gray-50' : '' }}">
                                        <td class="px-2 py-2 whitespace-nowrap">{{ optional($charge->created_at)->format('Y-m-d') }}</td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ $charge->end_date }}</td>
                                        <td class="px-2 py-2 whitespace-nowrap">
                                            {{ $charge->subscription?->name ?? 'N/A' }} (#{{ $charge->subscription_id }})
                                            @if($chargePlanLabel)
                                                · {{ $chargePlanLabel }}
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ number_format(((int)$charge->price) / 100, 2, '.', ' ') }} &#8381;</td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ $charge->action }}</td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ $charge->is_rebilling ? '&#10003;' : '&mdash;' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="px-2 py-2 text-gray-500" colspan="6">&#1057;&#1087;&#1080;&#1089;&#1072;&#1085;&#1080;&#1081; &#1087;&#1086;&#1082;&#1072; &#1085;&#1077;&#1090;</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="mt-6">
                        <h4 class="text-sm font-semibold text-gray-900">Докупка трафика для режима при ограничениях</h4>
                        <div class="mt-2 max-h-56 overflow-auto rounded-md border border-gray-200">
                            <table class="min-w-full text-xs">
                                <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-2 py-2 text-left">Дата</th>
                                    <th class="px-2 py-2 text-left">Действует до</th>
                                    <th class="px-2 py-2 text-left">Пакет</th>
                                    <th class="px-2 py-2 text-left">Трафик</th>
                                    <th class="px-2 py-2 text-left">Сумма</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse(($topupsHistory ?? collect()) as $topup)
                                    <tr class="{{ $loop->even ? 'bg-gray-50' : '' }}">
                                        <td class="px-2 py-2 whitespace-nowrap">{{ optional($topup->created_at)->format('Y-m-d H:i') }}</td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ optional($topup->expires_on)->format('Y-m-d') }}</td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ $topup->name }}</td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ number_format(((int) $topup->traffic_bytes) / 1073741824, 0, '.', ' ') }} ГБ</td>
                                        <td class="px-2 py-2 whitespace-nowrap">{{ number_format(((int) $topup->price) / 100, 2, '.', ' ') }} &#8381;</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="px-2 py-2 text-gray-500" colspan="5">Пакетов трафика пока нет</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 rounded-md border border-blue-100 bg-blue-50 p-3 text-xs leading-relaxed text-blue-900">
                            &#1055;&#1086;&#1076;&#1087;&#1080;&#1089;&#1082;&#1072; &#1087;&#1088;&#1086;&#1076;&#1083;&#1077;&#1074;&#1072;&#1077;&#1090;&#1089;&#1103; &#1087;&#1086; &#1082;&#1072;&#1083;&#1077;&#1085;&#1076;&#1072;&#1088;&#1085;&#1099;&#1084; &#1084;&#1077;&#1089;&#1103;&#1094;&#1072;&#1084;: &#1085;&#1086;&#1074;&#1099;&#1081; &#1089;&#1088;&#1086;&#1082; &#1089;&#1095;&#1080;&#1090;&#1072;&#1077;&#1090;&#1089;&#1103; &#1082;&#1072;&#1082; +1 &#1084;&#1077;&#1089;&#1103;&#1094; &#1086;&#1090; &#1076;&#1072;&#1090;&#1099; &#1086;&#1082;&#1086;&#1085;&#1095;&#1072;&#1085;&#1080;&#1103; &#1087;&#1088;&#1086;&#1096;&#1083;&#1086;&#1075;&#1086; &#1087;&#1077;&#1088;&#1080;&#1086;&#1076;&#1072;, &#1072; &#1087;&#1086;&#1089;&#1083;&#1077; &#1087;&#1072;&#1091;&#1079;&#1099; (&#1086;&#1078;&#1080;&#1076;&#1072;&#1085;&#1080;&#1103; &#1086;&#1087;&#1083;&#1072;&#1090;&#1099;) &mdash; &#1086;&#1090; &#1076;&#1072;&#1090;&#1099; &#1092;&#1072;&#1082;&#1090;&#1080;&#1095;&#1077;&#1089;&#1082;&#1086;&#1081; &#1086;&#1087;&#1083;&#1072;&#1090;&#1099;.<br>
                            &#1045;&#1089;&#1083;&#1080; &#1086;&#1082;&#1086;&#1085;&#1095;&#1072;&#1085;&#1080;&#1077; &#1074;&#1099;&#1087;&#1072;&#1076;&#1072;&#1077;&#1090; &#1085;&#1072; &#1087;&#1086;&#1089;&#1083;&#1077;&#1076;&#1085;&#1080;&#1081; &#1076;&#1077;&#1085;&#1100; &#1084;&#1077;&#1089;&#1103;&#1094;&#1072;, &#1089;&#1083;&#1077;&#1076;&#1091;&#1102;&#1097;&#1080;&#1081; &#1089;&#1088;&#1086;&#1082; &#1090;&#1072;&#1082;&#1078;&#1077; &#1089;&#1090;&#1072;&#1074;&#1080;&#1090;&#1089;&#1103; &#1085;&#1072; &#1087;&#1086;&#1089;&#1083;&#1077;&#1076;&#1085;&#1080;&#1081; &#1076;&#1077;&#1085;&#1100; &#1089;&#1083;&#1077;&#1076;&#1091;&#1102;&#1097;&#1077;&#1075;&#1086; &#1084;&#1077;&#1089;&#1103;&#1094;&#1072; (31.01 -&gt; 28.02 -&gt; 31.03).<br>
                            &#1042; &#1076;&#1077;&#1085;&#1100; &#1086;&#1082;&#1086;&#1085;&#1095;&#1072;&#1085;&#1080;&#1103; &#1089;&#1080;&#1089;&#1090;&#1077;&#1084;&#1072; &#1087;&#1088;&#1086;&#1074;&#1077;&#1088;&#1103;&#1077;&#1090; &#1073;&#1072;&#1083;&#1072;&#1085;&#1089;: &#1087;&#1088;&#1080; &#1091;&#1089;&#1087;&#1077;&#1096;&#1085;&#1086;&#1084; &#1089;&#1087;&#1080;&#1089;&#1072;&#1085;&#1080;&#1080; &#1087;&#1088;&#1086;&#1076;&#1083;&#1077;&#1074;&#1072;&#1077;&#1090; &#1076;&#1086;&#1089;&#1090;&#1091;&#1087;, &#1087;&#1088;&#1080; &#1085;&#1077;&#1076;&#1086;&#1089;&#1090;&#1072;&#1090;&#1082;&#1077; &#1089;&#1088;&#1077;&#1076;&#1089;&#1090;&#1074; &#1087;&#1077;&#1088;&#1077;&#1074;&#1086;&#1076;&#1080;&#1090; &#1087;&#1086;&#1076;&#1087;&#1080;&#1089;&#1082;&#1091; &#1074; &#1086;&#1078;&#1080;&#1076;&#1072;&#1085;&#1080;&#1077; &#1086;&#1087;&#1083;&#1072;&#1090;&#1099;.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
