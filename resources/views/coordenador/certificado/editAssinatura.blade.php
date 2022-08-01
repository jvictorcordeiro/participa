@extends('coordenador.detalhesEvento')

@section('menu')

    <div id="divCadastrarAssinatura" class="comissao">
        <div class="row">
            <div class="col-sm-12">
                <h1 class="titulo-detalhes">Editar Assinatura</h1>
                <h6 class="titulo-detalhes">Edite uma assinatura para certificados</h6>
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <form id="formEditarAssinatura" action="{{route('coord.assinatura.update', ['id' => $assinatura->id])}}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="eventoId" value="{{$assinatura->evento->id}}">
            <div class="form-row">
                <div class="col-sm-6 form-group">
                    <label for="nome">{{ __('Nome') }}</label>
                    <input id="nome" class="form-control @error('nome') is-invalid @enderror" type="text" name="nome" value="{{old('nome')!=null ? old('nome') : $assinatura->nome}}"  required autofocus autocomplete="nome">

                    @error('nome')
                        <div id="validationServer03Feedback" class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>
                <div class="col-sm-6 form-group">
                    <label for="cargo">{{ __('Cargo') }}</label>
                    <input id="cargo" class="form-control @error('cargo') is-invalid @enderror" type="text" name="cargo" value="{{old('cargo')!=null ? old('cargo') : $assinatura->cargo}}"  required autofocus autocomplete="cargo">

                    @error('cargo')
                        <div id="validationServer03Feedback" class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div class="col-sm-6 form-group">
                    <div class="col-sm-5">
                        <div class="form-group">
                            <label for="fotoAssinatura">Assinatura</label>
                            <div id="imagem-loader" class="imagem-loader">
                                @if ($assinatura->caminho != null)
                                    <img id="logo-preview" class="img-fluid" src="{{asset('storage/'.$assinatura->caminho)}}" alt="">
                                @else
                                    <img id="logo-preview" class="img-fluid" src="{{asset('/img/nova_imagem.PNG')}}" alt="">
                                @endif
                            </div>
                            <div style="display: none;">
                                <input type="file" id="logo-input" accept="image/*" class="form-control @error('fotoAssinatura') is-invalid @enderror" name="fotoAssinatura" value="{{ old('fotoAssinatura') }}">
                            </div>
                            <small style="position: relative; top: 5px;">Tamanho minimo: 1024 x 425;<br>Formato: JPEG, JPG, PNG</small>

                            @error('fotoAssinatura')
                                <span class="invalid-feedback" role="alert">
                                    <br>
                                    <strong>{{$message}}</strong>
                                </span>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
            <div class="row justify-content-center">
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary" style="width:100%">
                        {{ __('Editar') }}
                    </button>
                </div>
            </div>
        </form>
    </div>

@endsection

@section('javascript')
    @parent
    <script type="text/javascript" >
        document.getElementById("logo-input").src = document.getElementById("logo-preview").src;
        $(document).ready(function(){
            $('#imagem-loader').click(function() {
                $('#logo-input').click();
                $('#logo-input').change(function() {
                    if (this.files && this.files[0]) {
                        var file = new FileReader();
                        file.onload = function(e) {
                            document.getElementById("logo-preview").src = e.target.result;
                        };
                        file.readAsDataURL(this.files[0]);
                    }
                });
            });
        });
    </script>
@endsection
