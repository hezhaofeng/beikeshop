@extends('layout.mail')

@section('content')
  <tbody>
    <tr>
      <td style="width:3.2%;max-width:30px;"></td>
      <td style="max-width:480px;text-align:left;font-size:14px;line-height:24px;">
        {!! $body !!}
      </td>
      <td style="width:3.2%;max-width:30px;"></td>
    </tr>
  </tbody>
@endsection
