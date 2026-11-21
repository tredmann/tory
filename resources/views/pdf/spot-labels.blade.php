<!doctype html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <style>
            body {
                margin: 0;
                font-family: 'DejaVu Sans', sans-serif;
                color: #000;
            }

            table {
                border-collapse: collapse;
                table-layout: fixed;
            }

            tr {
                break-inside: avoid;
            }

            td {
                padding: 0;
                border: 0.2mm solid #999;
            }

            .label {
                display: flex;
                align-items: center;
                overflow: hidden;
            }

            .text {
                position: relative;
                flex: 1;
                min-width: 0;
                padding-right: 2mm;
            }

            .code {
                margin-top: -0.19em;
                font-weight: bold;
                line-height: 1;
                white-space: nowrap;
            }

            .name {
                overflow-wrap: anywhere;
                line-height: 1.2;
            }

            .address {
                position: absolute;
                right: 2mm;
                bottom: 0.3mm;
                left: 0;
                line-height: 1.1;
            }

            /* .text is as high as the black part of the QR code: its image has a white border of 4 of 37 modules. */
            .small td, .small .label { width: 50mm; height: 30mm; }
            .small img { width: 26mm; height: 26mm; }
            .small .text { height: 20.4mm; }
            .small .code { font-size: 15pt; }
            .small .name { font-size: 7.5pt; line-clamp: 3; }
            .small .address { font-size: 5.5pt; }

            .medium td, .medium .label { width: 70mm; height: 40mm; }
            .medium img { width: 38mm; height: 38mm; }
            .medium .text { height: 29.8mm; }
            .medium .code { font-size: 22pt; }
            .medium .name { font-size: 10pt; line-clamp: 4; }
            .medium .address { font-size: 6.5pt; }

            .large td, .large .label { width: 100mm; height: 70mm; }
            .large img { width: 60mm; height: 60mm; }
            .large .text { height: 47mm; }
            .large .code { font-size: 26pt; }
            .large .name { font-size: 12pt; line-clamp: 6; }
            .large .address { font-size: 7.5pt; }
        </style>
    </head>
    <body>
        <table class="{{ $size->value }}">
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $label)
                        <td>
                            <div class="label">
                                <img src="{{ $label->qrCode }}" alt="">
                                <div class="text">
                                    <div class="code">{{ $label->code }}</div>
                                    <div class="name">{{ $label->name }}</div>
                                    <div class="address">{!! str_replace('/', '/<wbr>', e($label->address)) !!}</div>
                                </div>
                            </div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </body>
</html>
