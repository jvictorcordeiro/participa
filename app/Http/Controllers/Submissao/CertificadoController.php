<?php

namespace App\Http\Controllers\Submissao;

use App\Http\Controllers\Controller;
use App\Http\Requests\CertificadoRequest;
use App\Http\Requests\UpdateCertificadoRequest;
use App\Mail\EmailCertificado;
use App\Mail\EmailCertificadoSemAnexo;
use App\Models\Inscricao\Inscricao;
use App\Models\Submissao\Assinatura;
use App\Models\Submissao\Atividade;
use App\Models\Submissao\Certificado;
use App\Models\Submissao\Evento;
use App\Models\Submissao\Medida;
use App\Models\Submissao\Palestra;
use App\Models\Submissao\Palestrante;
use App\Models\Submissao\TipoComissao;
use App\Models\Submissao\Trabalho;
use App\Models\Users\Coautor;
use App\Models\Users\Revisor;
use App\Models\Users\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\File;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CertificadoController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $evento = Evento::find($request->eventoId);
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        $certificados = Certificado::where('evento_id', $evento->id)->get();
        //dd($certificados);
        return view('coordenador.certificado.index', [
            'evento' => $evento,
            'certificados' => $certificados,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return View
     *
     * @throws AuthorizationException
     */
    public function create(Request $request)
    {
        $evento = Evento::find($request->eventoId);
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        $assinaturas = Assinatura::where('evento_id', $evento->id)->get();
        $tipos = Certificado::TIPO_ENUM;
        //dd($tipos[]);
        return view('coordenador.certificado.create', compact('evento', 'assinaturas', 'tipos'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function store(CertificadoRequest $request)
    {
        $evento = Evento::find($request->eventoId);
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        $validated = $request->validated();
        $certificado = new Certificado();
        $certificado->setAtributes($validated);
        $certificado->evento_id = $evento->id;

        $imagem = $request->fotoCertificado;
        $path = 'certificados/'.$evento->id;
        $novo_caminho = $certificado->uploadArquivo($path, true, $imagem);
        $certificado->caminho = $novo_caminho;
        if ($certificado->has_imagem_verso) {
            $verso = $validated['imagem_verso'];
            $caminho_verso = $certificado->uploadArquivo($path, true, $verso);
            $certificado->imagem_verso = $caminho_verso;
        }
        $certificado->save();

        if (! $request->imagem_assinada) {
            foreach ($request->assinaturas as $assinatura_id) {
                $certificado->assinaturas()->attach(Assinatura::find($assinatura_id));
            }
        }

        return redirect(route('coord.listarCertificados', ['eventoId' => $evento->id]))->with(['success' => 'Certificado cadastrado com sucesso.']);
    }

    public function duplicar(Request $request, Certificado $certificado)
    {
        $evento = $certificado->evento;
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        $novo = $certificado->replicate();
        $novo->nome = $request->nome;
        $novo->save();
        $path = 'certificados/'.$evento->id;
        $imagem = Storage::disk('public')->path($novo->caminho);
        $novo_caminho = Storage::disk('public')->putFile($path, new File($imagem));
        $novo->caminho = $novo_caminho;
        if ($novo->has_imagem_verso) {
            $verso = Storage::disk('public')->path($novo->imagem_verso);
            $caminho_verso = Storage::disk('public')->putFile($path, new File($verso));
            $novo->imagem_verso = $caminho_verso;
        }
        if ($certificado->assinaturas()->exists()) {
            $novo->assinaturas()->saveMany($certificado->assinaturas);
        }
        $medidas = $certificado->medidas;
        $novas_medidas = collect();
        foreach ($medidas as $medida) {
            $novas_medidas->push($medida->replicate(['certificado_id']));
        }
        $novo->save();
        $novo->medidas()->saveMany($novas_medidas);

        return redirect(route('coord.listarCertificados', ['eventoId' => $evento->id]))->with(['success' => 'Certificado duplicado com sucesso.']);
    }

    /**
     * Display the specified resource.
     *
     * @return Response
     */
    public function show(Certificado $certificado)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return View
     */
    public function edit(Request $request, $id)
    {
        $evento = Evento::find($request->eventoId);
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        $certificado = Certificado::find($id);
        $assinaturas = Assinatura::where('evento_id', $evento->id)->get();
        $tipos = [1 => 'Apresentadores', 'Membro da comissão científica', 'Membro da comissão organizadora', 'Revisores', 'Participantes', 'Palestrante', 'Coordenador da comissão científica', 'Membro de outra comissão', 'Inscrito em uma atividade', 'Inscrito no evento'];

        return view('coordenador.certificado.edit', compact('assinaturas', 'certificado', 'evento', 'tipos'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function update(UpdateCertificadoRequest $request, $id)
    {
        $evento = Evento::find($request->eventoId);
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        $validatedData = $request->validated();
        $certificado = Certificado::find($id);
        $certificado->setAtributes($validatedData);

        if ($request->fotoCertificado != null) {
            $request->validate([
                'fotoCertificado' => 'required|file|mimes:png,jpeg,jpg|max:2048',
            ]);
            if (Storage::disk('public')->exists($certificado->caminho)) {
                Storage::disk('public')->delete($certificado->caminho);
            }

            $imagem = $request->fotoCertificado;
            $path = 'certificados/'.$evento->id;
            $novo_caminho = $certificado->uploadArquivo($path, true, $imagem);
            $certificado->caminho = $novo_caminho;
        }

        if ($request->imagem_verso != null || ! $validatedData['verso'] || ! $validatedData['has_imagem_verso']) {
            if ($certificado->imagem_verso != null && Storage::disk('public')->exists($certificado->imagem_verso)) {
                Storage::disk('public')->delete($certificado->imagem_verso);
            }
            $certificado->imagem_verso = null;
        }

        if ($request->imagem_verso != null) {
            $imagem = $request->imagem_verso;
            $path = 'certificados/'.$evento->id;
            $novo_caminho = $certificado->uploadArquivo($path, true, $imagem);
            $certificado->imagem_verso = $novo_caminho;
        }

        if (! $request->imagem_assinada) {
            foreach ($request->assinaturas as $assinatura_id) {
                if ($certificado->assinaturas()->where('assinatura_id', $assinatura_id)->first() == null) {
                    $certificado->assinaturas()->attach(Assinatura::find($assinatura_id));
                }
            }

            foreach ($certificado->assinaturas as $assinatura) {
                if (! (in_array($assinatura->id, $request->assinaturas))) {
                    $certificado->assinaturas()->detach($assinatura->id);
                }
            }
        }

        $certificado->update();

        return redirect(route('coord.listarCertificados', ['eventoId' => $evento->id]))->with(['success' => 'Certificado editado com sucesso.']);
    }

    public function salvarMedida(Request $request)
    {

        $certificado = Certificado::find($request->certificado_id);
        $evento = $certificado->evento;
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        $assinaturas = $certificado->assinaturas;
        $assinaturas_id = $assinaturas->pluck('id');
        $medida = Medida::firstOrNew(['certificado_id' => $certificado->id, 'tipo' => Medida::TIPO_ENUM['texto']]);
        $medida->x = $request['texto-x'];
        $medida->y = $request['texto-y'];
        $medida->largura = $request['texto-largura'];
        $medida->fontSize = intval($request['texto-fontSize']);
        $medida->save();

        $medida = Medida::firstOrNew(['certificado_id' => $certificado->id, 'tipo' => Medida::TIPO_ENUM['data']]);
        $medida->x = $request['data-x'];
        $medida->y = $request['data-y'];
        $medida->largura = $request['data-largura'];
        $medida->fontSize = intval($request['data-fontSize']);
        $medida->save();

        $medida = Medida::firstOrNew(['certificado_id' => $certificado->id, 'tipo' => Medida::TIPO_ENUM['hash']]);
        $medida->x = $request['hash-x'];
        $medida->y = $request['hash-y'];
        $medida->largura = $request['hash-largura'];
        $medida->fontSize = intval($request['hash-fontSize']);
        $medida->save();

        $medida = Medida::firstOrNew(['certificado_id' => $certificado->id, 'tipo' => Medida::TIPO_ENUM['emissao']]);
        $medida->x = $request['emissao-x'];
        $medida->y = $request['emissao-y'];
        $medida->largura = $request['emissao-largura'];
        $medida->fontSize = intval($request['emissao-fontSize']);
        $medida->save();

        $medida = Medida::firstOrNew(['certificado_id' => $certificado->id, 'tipo' => Medida::TIPO_ENUM['qrcode']]);
        $medida->x = $request['qrcode-x'];
        $medida->y = $request['qrcode-y'];
        $medida->largura = $request['qrcode-largura'];
        $medida->altura = intval($request['qrcode-altura']);
        $medida->save();

        $medida = Medida::firstOrNew(['certificado_id' => $certificado->id, 'tipo' => Medida::TIPO_ENUM['logo']]);
        $medida->x = $request['logo-x'];
        $medida->y = $request['logo-y'];
        $medida->largura = $request['logo-largura'];
        $medida->altura = intval($request['logo-altura']);
        $medida->save();

        if (! $certificado->imagem_assinada) {
            foreach ($assinaturas_id as $id) {
                $medida = Medida::firstOrNew(['certificado_id' => $certificado->id, 'tipo' => Medida::TIPO_ENUM['cargo_assinatura'], 'assinatura_id' => $id]);
                $medida->x = $request['cargo-x-'.$id];
                $medida->y = $request['cargo-y-'.$id];
                $medida->largura = $request['cargo-largura-'.$id];
                $medida->fontSize = intval($request['cargo-fontSize-'.$id]);
                $medida->save();

                $medida = Medida::firstOrNew(['certificado_id' => $certificado->id, 'tipo' => Medida::TIPO_ENUM['nome_assinatura'], 'assinatura_id' => $id]);
                $medida->x = $request['nome-x-'.$id];
                $medida->y = $request['nome-y-'.$id];
                $medida->largura = $request['nome-largura-'.$id];
                $medida->fontSize = intval($request['nome-fontSize-'.$id]);
                $medida->save();

                $medida = Medida::firstOrNew(['certificado_id' => $certificado->id, 'tipo' => Medida::TIPO_ENUM['imagem_assinatura'], 'assinatura_id' => $id]);
                $medida->x = $request['imagem-x-'.$id];
                $medida->y = $request['imagem-y-'.$id];
                $medida->largura = $request['imagem-largura-'.$id];
                $medida->altura = $request['imagem-altura-'.$id];
                $medida->save();

                $medida = Medida::firstOrNew(['certificado_id' => $certificado->id, 'tipo' => Medida::TIPO_ENUM['linha_assinatura'], 'assinatura_id' => $id]);
                $medida->x = $request['linha-x-'.$id];
                $medida->y = $request['linha-y-'.$id];
                $medida->largura = $request['linha-largura-'.$id];
                $medida->save();
            }
        }

        return redirect()->route('coord.listarCertificados', ['eventoId' => $certificado->evento->id]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Submissao\Certificado  $certificado
     * @return Response
     */
    public function destroy($id)
    {
        $certificado = Certificado::find($id);
        $evento = $certificado->evento;
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        if (Storage::disk('public')->exists($certificado->caminho)) {
            Storage::disk('public')->delete($certificado->caminho);
        }
        $certificado->delete();

        return redirect(route('coord.listarCertificados', ['eventoId' => $evento->id]))->with(['success' => 'Certificado deletado com sucesso.']);
    }

    public function modelo($id)
    {
        $certificado = Certificado::find($id);
        $medidas = $certificado->medidas()->with('assinatura')->get();
        $evento = $certificado->evento;
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        $dataHoje = $certificado->data->isoFormat('LL');

        return view('coordenador.certificado.modelo', compact('certificado', 'medidas', 'evento', 'dataHoje'));
    }

    public function previewCertificado($certificadoId, $destinatarioId, $trabalhoId)
    {
        $certificado = Certificado::find($certificadoId);
        $evento = $certificado->evento;
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);

        return $this->gerar_pdf_certificado($certificado, $destinatarioId, $trabalhoId, $evento);
    }

    public function visualizar_certificado_emitido($certificadoId, $destinatarioId, $trabalhoId)
    {
        $certificado = Certificado::withTrashed()
            ->where('id', $certificadoId)
            ->first();
        if (auth()->user()->id != $destinatarioId) {
            return redirect()->back()->with('certificado', 'Você não possui autorização para ver este certificado');
        }
        $evento = $certificado->evento;

        return $this->gerar_pdf_certificado($certificado, $destinatarioId, $trabalhoId, $evento);
    }

    private function gerar_pdf_certificado($certificado, $destinatarioId, $trabalhoId, $evento)
    {
        $certificado_user = DB::table('certificado_user')->where([
            ['certificado_id', '=', $certificado->id],
            ['user_id', '=', $destinatarioId],
        ])->first();
        if (is_null($certificado_user)) {
            return redirect()->back()->with('certificado', 'Falha ao recuperar o certificado');
        }
        $validacao = $certificado_user->validacao;
        switch ($certificado->tipo) {
            case Certificado::TIPO_ENUM['apresentador']:
                $validacao = DB::table('certificado_user')->where([
                    ['certificado_id', '=', $certificado->id],
                    ['user_id', '=', $destinatarioId],
                    ['trabalho_id', '=', $trabalhoId],
                ])->first()->validacao;
                break;
            case Certificado::TIPO_ENUM['expositor']:
                $validacao = DB::table('certificado_user')->where([
                    ['certificado_id', '=', $certificado->id],
                    ['user_id', '=', $destinatarioId],
                    ['palestra_id', '=', $trabalhoId],
                ])->first()->validacao;
                break;
            case Certificado::TIPO_ENUM['outras_comissoes']:
                $validacao = DB::table('certificado_user')->where([
                    ['certificado_id', '=', $certificado->id],
                    ['user_id', '=', $destinatarioId],
                    ['comissao_id', '=', $trabalhoId],
                ])->first()->validacao;
                break;
            case Certificado::TIPO_ENUM['inscrito_atividade']:
                $validacao = DB::table('certificado_user')->where([
                    ['certificado_id', '=', $certificado->id],
                    ['user_id', '=', $destinatarioId],
                    ['atividade_id', '=', $trabalhoId],
                ])->first()->validacao;
                break;
            default:
                break;
        }
        $qrcode = base64_encode(QrCode::generate($validacao));
        switch ($certificado->tipo) {
            case Certificado::TIPO_ENUM['apresentador']:
                $user = User::find($destinatarioId);
                $trabalho = Trabalho::find($trabalhoId);
                $coautores = $trabalho->coautors()->with('user')->get()->pluck('user.name')->join(', ', ' e ');
                $texto = $certificado->texto;
                if ($coautores != '') {
                    $texto = preg_replace('/%MSG_COAUTORES=(.*?)%/', '$1', $texto);
                } else {
                    $texto = preg_replace('/%MSG_COAUTORES=(.*?)%/', '', $texto);
                }
                $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacao, 'certificado' => $certificado, 'user' => $user, 'trabalho' => $trabalho, 'coautores' => $coautores, 'cargo' => 'Apresentador', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                break;
            case Certificado::TIPO_ENUM['comissao_cientifica']:
                $user = User::find($destinatarioId);
                $texto = $certificado->texto;
                $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacao, 'certificado' => $certificado, 'user' => $user, 'cargo' => 'Comissão Científica', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                break;
            case Certificado::TIPO_ENUM['comissao_organizadora']:
                $user = User::find($destinatarioId);
                $texto = $certificado->texto;
                $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacao, 'certificado' => $certificado, 'user' => $user, 'cargo' => 'Comissão Organizadora', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                break;
            case Certificado::TIPO_ENUM['revisor']:
                $user = User::find($destinatarioId);
                $texto = $certificado->texto;
                $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacao, 'certificado' => $certificado, 'user' => $user, 'cargo' => 'Revisor', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                break;
            case Certificado::TIPO_ENUM['participante']:
                $user = User::find($destinatarioId);
                $texto = $certificado->texto;
                $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacao, 'certificado' => $certificado, 'user' => $user, 'cargo' => 'Participante', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                break;
            case Certificado::TIPO_ENUM['inscrito']:
                $user = User::find($destinatarioId);
                $texto = $certificado->texto;
                $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacao, 'certificado' => $certificado, 'user' => $user, 'cargo' => 'Inscrito', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                break;
            case Certificado::TIPO_ENUM['expositor']:
                $user = Palestrante::find($destinatarioId);
                $palestra = Palestra::find($trabalhoId);
                $texto = $certificado->texto;
                $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacao, 'certificado' => $certificado, 'user' => $user, 'palestra' => $palestra, 'cargo' => 'Expositor', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                break;
            case Certificado::TIPO_ENUM['coordenador_comissao_cientifica']:
                $user = User::find($destinatarioId);
                $trabalho = Trabalho::find($trabalhoId);
                $texto = $certificado->texto;
                $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacao, 'certificado' => $certificado, 'user' => $user, 'trabalho' => $trabalho, 'cargo' => 'Coordenador comissão científica', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                break;
            case Certificado::TIPO_ENUM['outras_comissoes']:
                $user = User::find($destinatarioId);
                $comissao = TipoComissao::find($trabalhoId);
                $texto = $certificado->texto;
                $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacao, 'certificado' => $certificado, 'user' => $user, 'comissao' => $comissao, 'cargo' => "membro da comissao {$comissao->nome}", 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                break;
            case Certificado::TIPO_ENUM['inscrito_atividade']:
                $user = User::find($destinatarioId);
                $atividade = Atividade::find($trabalhoId);
                $texto = $certificado->texto;
                $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacao, 'certificado' => $certificado, 'user' => $user, 'atividade' => $atividade, 'cargo' => "inscrito na atividade {$atividade->titulo}", 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                break;
        }

        return $pdf->stream('preview.pdf');
    }

    public function get_string_between($string, $start, $end)
    {
        $ini = strpos($string, $start);
        if ($ini == 0) {
            return '';
        }
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;

        return substr($string, $ini, $len);
    }

    public function emitir(Request $request)
    {
        $evento = Evento::find($request->eventoId);
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        $certificados = Certificado::where('evento_id', $evento->id)->get();
        $destinatarios = [1 => 'Apresentadores', 'Membro da comissão científica', 'Membro da comissão organizadora', 'Revisores', 'Participantes', 'Palestrante', 'Coordenador da comissão científica', 'Membro de outra comissão', 'Inscrito em uma atividade', 'Inscrito no evento'];

        return view('coordenador.certificado.emissao', [
            'evento' => $evento,
            'certificados' => $certificados,
            'destinatarios' => $destinatarios,
        ]);
    }

    public function ajaxDestinatarios(Request $request)
    {
        if ($request->destinatario == Certificado::TIPO_ENUM['apresentador']) {
            $destinatarios = collect();
            $trab = Trabalho::select(['trabalhos.*', 'users.name as user_name'])->join('users', 'trabalhos.autorId', 'users.id')->where('eventoId', '=', $request->eventoId)->orderBy('user_name')->get();
            $trabalhos = collect();
            foreach ($trab as $trabalho) {
                $destinatarios->push($trabalho->autor);
                $trabalhos->push($trabalho);
                foreach ($trabalho->coautors as $coautor) {
                    $destinatarios->push($coautor->user);
                    $trabalhos->push($trabalho);
                }
            }
        } elseif ($request->destinatario == Certificado::TIPO_ENUM['comissao_cientifica'] || $request->destinatario == Certificado::TIPO_ENUM['coordenador_comissao_cientifica']) {
            $destinatarios = User::join('comissao_cientifica_eventos', 'users.id', '=', 'comissao_cientifica_eventos.user_id')->where('comissao_cientifica_eventos.evento_id', '=', $request->eventoId)->selectRaw('DISTINCT users.*')->get()->sortBy(
                function ($membro) {
                    return $membro->name;
                },
                SORT_REGULAR)->values()->all();
        } elseif ($request->destinatario == Certificado::TIPO_ENUM['comissao_organizadora']) {
            $destinatarios = User::join('comissao_organizadora_eventos', 'users.id', '=', 'comissao_organizadora_eventos.user_id')->where('comissao_organizadora_eventos.evento_id', '=', $request->eventoId)->selectRaw('DISTINCT users.*')->get()->sortBy(
                function ($membro) {
                    return $membro->name;
                },
                SORT_REGULAR)->values()->all();
        } elseif ($request->destinatario == Certificado::TIPO_ENUM['revisor']) {
            $destinatarios = User::join('revisors', 'users.id', '=', 'revisors.user_id')->where('revisors.evento_id', '=', $request->eventoId)->selectRaw('DISTINCT users.*')->get()->sortBy(
                function ($revisor) {
                    return $revisor->name;
                },
                SORT_REGULAR)->values()->all();
        } elseif ($request->destinatario == Certificado::TIPO_ENUM['participante']) {
            $autores = Trabalho::where('eventoId', $request->eventoId)->get()->pluck('autor');
            $cientifica = Evento::find($request->eventoId)->usuariosDaComissao;
            $organizadora = Evento::find($request->eventoId)->usuariosDaComissaoOrganizadora;
            $revisores = Revisor::where('evento_id', $request->eventoId)->get()->pluck('user');
            $coautores = Coautor::where('eventos_id', $request->eventoId)->get()->pluck('user');
            $inscritos = Inscricao::where('evento_id', $request->eventoId)->get()->pluck('user');
            $destinatarios = $autores->merge($cientifica)
                ->merge($organizadora)
                ->merge($revisores)
                ->merge($coautores)
                ->merge($inscritos)
                ->sortBy('name')->values()->unique('id')->all();
        } elseif ($request->destinatario == Certificado::TIPO_ENUM['inscrito']) {
            $destinatarios = Inscricao::where('evento_id', $request->eventoId)->get()->pluck('user');
        } elseif ($request->destinatario == Certificado::TIPO_ENUM['expositor']) {
            $destinatarios = Evento::find($request->eventoId)->palestrantes()->orderBy('nome')->get();
            $palestras = $destinatarios->map(function ($destinatario) {
                return $destinatario->palestra;
            });
        } elseif ($request->destinatario == Certificado::TIPO_ENUM['outras_comissoes']) {
            $comissao = TipoComissao::find($request->tipo_comissao_id);
            $destinatarios = $comissao->membros()->orderBy('name')->get();
        } elseif ($request->destinatario == Certificado::TIPO_ENUM['inscrito_atividade']) {
            $destinatarios = collect();
            if ($request->atividade == '0') {
                $ativ = Atividade::where('eventoId', $request->eventoId)->orderBy('titulo')->get();
            } else {
                $ativ = Atividade::where('id', $request->atividade)->get();
            }
            $atividades = collect();
            foreach ($ativ as $atividade) {
                foreach ($atividade->users()->orderBy('name')->get() as $destinatario) {
                    $destinatarios->push($destinatario);
                    $atividades->push($atividade);
                }
            }
        }
        switch ($request->destinatario) {
            case Certificado::TIPO_ENUM['apresentador']:
                $certificados = Certificado::where([['evento_id', $request->eventoId], ['tipo', Certificado::TIPO_ENUM['apresentador']]])->get();
                break;
            case Certificado::TIPO_ENUM['inscrito']:
                $certificados = Certificado::where([['evento_id', $request->eventoId], ['tipo', Certificado::TIPO_ENUM['inscrito']]])->get();
                break;
            case Certificado::TIPO_ENUM['comissao_cientifica']:
                $certificados = Certificado::where([['evento_id', $request->eventoId], ['tipo', Certificado::TIPO_ENUM['comissao_cientifica']]])->get();
                break;
            case Certificado::TIPO_ENUM['comissao_organizadora']:
                $certificados = Certificado::where([['evento_id', $request->eventoId], ['tipo', Certificado::TIPO_ENUM['comissao_organizadora']]])->get();
                break;
            case Certificado::TIPO_ENUM['revisor']:
                $certificados = Certificado::where([['evento_id', $request->eventoId], ['tipo', Certificado::TIPO_ENUM['revisor']]])->get();
                break;
            case Certificado::TIPO_ENUM['participante']:
                $certificados = Certificado::where([['evento_id', $request->eventoId], ['tipo', Certificado::TIPO_ENUM['participante']]])->get();
                break;
            case Certificado::TIPO_ENUM['expositor']:
                $certificados = Certificado::where([['evento_id', $request->eventoId], ['tipo', Certificado::TIPO_ENUM['expositor']]])->get();
                break;
            case Certificado::TIPO_ENUM['coordenador_comissao_cientifica']:
                $certificados = Certificado::where([['evento_id', $request->eventoId], ['tipo', Certificado::TIPO_ENUM['coordenador_comissao_cientifica']]])->get();
                break;
            case Certificado::TIPO_ENUM['outras_comissoes']:
                $certificados = Certificado::where([['evento_id', $request->eventoId], ['tipo', Certificado::TIPO_ENUM['outras_comissoes']], ['tipo_comissao_id', $request->tipo_comissao_id]])->get();
                break;
            case Certificado::TIPO_ENUM['inscrito_atividade']:
                if ($request->atividade == '0') {
                    $certificados = Certificado::where([['evento_id', $request->eventoId], ['tipo', Certificado::TIPO_ENUM['inscrito_atividade']]])->whereNull('atividade_id')->get();
                } else {
                    $certificados = Certificado::where([['evento_id', $request->eventoId], ['tipo', Certificado::TIPO_ENUM['inscrito_atividade']], ['atividade_id', $request->atividade]])->get();
                }
                break;
            default:
                break;
        }
        if ($request->destinatario == Certificado::TIPO_ENUM['apresentador']) {
            $data = [
                'success' => true,
                'destinatarios' => $destinatarios,
                'trabalhos' => $trabalhos,
                'certificados' => $certificados,
            ];
        } elseif ($request->destinatario == Certificado::TIPO_ENUM['expositor']) {
            $data = [
                'success' => true,
                'destinatarios' => $destinatarios,
                'palestras' => $palestras,
                'certificados' => $certificados,
            ];
        } elseif ($request->destinatario == Certificado::TIPO_ENUM['outras_comissoes']) {
            $data = [
                'success' => true,
                'destinatarios' => $destinatarios,
                'comissao' => $comissao,
                'certificados' => $certificados,
            ];
        } elseif ($request->destinatario == Certificado::TIPO_ENUM['inscrito_atividade']) {
            $data = [
                'success' => true,
                'destinatarios' => $destinatarios,
                'atividades' => $atividades,
                'certificados' => $certificados,
            ];
        } else {
            $data = [
                'success' => true,
                'destinatarios' => $destinatarios,
                'certificados' => $certificados,
            ];
        }
        echo json_encode($data);
    }

    public function enviarCertificacao(Request $request)
    {
        $request->validate(['certificado' => 'required']);
        $evento = Evento::find($request->eventoId);
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        $certificado = Certificado::find($request->certificado);
        if ($certificado->medidas->count() == 0) {
            return redirect()->back()->with('error', 'Atualize o modelo do certificado antes de realizar emissões');
        }
        $validacoes = collect($request->destinatarios)->map(function ($item) {
            return Hash::make($item);
        });
        switch ($request->destinatario) {
            case Certificado::TIPO_ENUM['apresentador']:
                foreach ($request->destinatarios as $i => $destinarioId) {
                    $qrcode = base64_encode(QrCode::generate($validacoes[$i]));
                    $certificado->usuarios()->attach($destinarioId, ['validacao' => $validacoes[$i], 'trabalho_id' => $request->trabalhos[$i]]);
                    $user = User::find($destinarioId);
                    $trabalho = Trabalho::find($request->trabalhos[$i]);
                    $coautores = $trabalho->coautors()->with('user')->get()->pluck('user.name')->join(', ', ' e ');
                    $texto = $certificado->texto;
                    if ($coautores != '') {
                        $texto = preg_replace('/%MSG_COAUTORES=(.*?)%/', '$1', $texto);
                    } else {
                        $texto = preg_replace('/%MSG_COAUTORES=(.*?)%/', '', $texto);
                    }
                    if ($request->boolean('sem_anexo')) {
                        $link = route('certificado.view', urlencode($validacoes[$i]));
                        Mail::to($user->email)->send(new EmailCertificadoSemAnexo($user, 'apresentador de trabalho', $evento->nome, $link));
                    } else {
                        $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacoes[$i], 'certificado' => $certificado, 'user' => $user, 'trabalho' => $trabalho, 'coautores' => $coautores, 'cargo' => 'Apresentador', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                        Mail::to($user->email)->send(new EmailCertificado($user, 'apresentador de trabalho', $evento->nome, $pdf));
                    }
                }
                break;
            case Certificado::TIPO_ENUM['comissao_cientifica']:
                foreach ($request->destinatarios as $i => $destinarioId) {
                    $qrcode = base64_encode(QrCode::generate($validacoes[$i]));
                    $certificado->usuarios()->attach($destinarioId, ['validacao' => $validacoes[$i]]);
                    $user = User::find($destinarioId);
                    $texto = $certificado->texto;
                    if ($request->boolean('sem_anexo')) {
                        $link = route('certificado.view', urlencode($validacoes[$i]));
                        Mail::to($user->email)->send(new EmailCertificadoSemAnexo($user, 'membro da Comissão Científica', $evento->nome, $link));
                    } else {
                        $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacoes[$i], 'certificado' => $certificado, 'user' => $user, 'cargo' => 'Comissão Científica', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                        Mail::to($user->email)->send(new EmailCertificado($user, 'membro da Comissão Científica', $evento->nome, $pdf));
                    }
                }
                break;
            case Certificado::TIPO_ENUM['comissao_organizadora']:
                foreach ($request->destinatarios as $i => $destinarioId) {
                    $qrcode = base64_encode(QrCode::generate($validacoes[$i]));
                    $certificado->usuarios()->attach($destinarioId, ['validacao' => $validacoes[$i]]);
                    $user = User::find($destinarioId);
                    $texto = $certificado->texto;
                    if ($request->boolean('sem_anexo')) {
                        $link = route('certificado.view', urlencode($validacoes[$i]));
                        Mail::to($user->email)->send(new EmailCertificadoSemAnexo($user, 'membro da Comissão Organizadora', $evento->nome, $link));
                    } else {
                        $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacoes[$i], 'certificado' => $certificado, 'user' => $user, 'cargo' => 'Comissão Organizadora', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                        Mail::to($user->email)->send(new EmailCertificado($user, 'membro da Comissão Organizadora', $evento->nome, $pdf));
                    }
                }
                break;
            case Certificado::TIPO_ENUM['revisor']:
                foreach ($request->destinatarios as $i => $destinarioId) {
                    $qrcode = base64_encode(QrCode::generate($validacoes[$i]));
                    $certificado->usuarios()->attach($destinarioId, ['validacao' => $validacoes[$i]]);
                    $user = User::find($destinarioId);
                    $texto = $certificado->texto;
                    if ($request->boolean('sem_anexo')) {
                        $link = route('certificado.view', urlencode($validacoes[$i]));
                        Mail::to($user->email)->send(new EmailCertificadoSemAnexo($user, 'avaliador/a', $evento->nome, $link));
                    } else {
                        $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacoes[$i], 'certificado' => $certificado, 'user' => $user, 'cargo' => 'Revisor', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                        Mail::to($user->email)->send(new EmailCertificado($user, 'avaliador/a', $evento->nome, $pdf));
                    }
                }
                break;
            case Certificado::TIPO_ENUM['participante']:
                foreach ($request->destinatarios as $i => $destinarioId) {
                    $qrcode = base64_encode(QrCode::generate($validacoes[$i]));
                    $certificado->usuarios()->attach($destinarioId, ['validacao' => $validacoes[$i]]);
                    $user = User::find($destinarioId);
                    $texto = $certificado->texto;
                    if ($request->boolean('sem_anexo')) {
                        $link = route('certificado.view', urlencode($validacoes[$i]));
                        Mail::to($user->email)->send(new EmailCertificadoSemAnexo($user, 'participante', $evento->nome, $link));
                    } else {
                        $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacoes[$i], 'certificado' => $certificado, 'user' => $user, 'cargo' => 'Participante', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                        Mail::to($user->email)->send(new EmailCertificado($user, 'participante', $evento->nome, $pdf));
                    }
                }
                break;
            case Certificado::TIPO_ENUM['inscrito']:
                foreach ($request->destinatarios as $i => $destinarioId) {
                    $qrcode = base64_encode(QrCode::generate($validacoes[$i]));
                    $certificado->usuarios()->attach($destinarioId, ['validacao' => $validacoes[$i]]);
                    $user = User::find($destinarioId);
                    $texto = $certificado->texto;
                    if ($request->boolean('sem_anexo')) {
                        $link = route('certificado.view', urlencode($validacoes[$i]));
                        Mail::to($user->email)->send(new EmailCertificadoSemAnexo($user, 'inscrito', $evento->nome, $link));
                    } else {
                        $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacoes[$i], 'certificado' => $certificado, 'user' => $user, 'cargo' => 'inscrito', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                        Mail::to($user->email)->send(new EmailCertificado($user, 'inscrito', $evento->nome, $pdf));
                    }
                }
                break;
            case Certificado::TIPO_ENUM['expositor']:
                foreach ($request->destinatarios as $i => $destinarioId) {
                    $qrcode = base64_encode(QrCode::generate($validacoes[$i]));
                    $certificado->usuarios()->attach($destinarioId, ['validacao' => $validacoes[$i], 'palestra_id' => $request->palestras[$i]]);
                    $user = Palestrante::find($destinarioId);
                    $palestra = Palestra::find($request->palestras[$i]);
                    $texto = $certificado->texto;
                    if ($request->boolean('sem_anexo')) {
                        $link = route('certificado.view', urlencode($validacoes[$i]));
                        Mail::to($user->email)->send(new EmailCertificadoSemAnexo($user, 'palestrante', $evento->nome, $link));
                    } else {
                        $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacoes[$i], 'certificado' => $certificado, 'user' => $user, 'palestra' => $palestra, 'cargo' => 'Expositor', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                        Mail::to($user->email)->send(new EmailCertificado($user, 'palestrante', $evento->nome, $pdf));
                    }
                }
                break;
            case Certificado::TIPO_ENUM['coordenador_comissao_cientifica']:
                foreach ($request->destinatarios as $i => $destinarioId) {
                    $qrcode = base64_encode(QrCode::generate($validacoes[$i]));
                    $certificado->usuarios()->attach($destinarioId, ['validacao' => $validacoes[$i]]);
                    $user = User::find($destinarioId);
                    $texto = $certificado->texto;
                    if ($request->boolean('sem_anexo')) {
                        $link = route('certificado.view', urlencode($validacoes[$i]));
                        Mail::to($user->email)->send(new EmailCertificadoSemAnexo($user, 'coordenador/a da comissão Científica', $evento->nome, $link));
                    } else {
                        $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacoes[$i], 'certificado' => $certificado, 'user' => $user, 'cargo' => 'Coordenador comissão científica', 'evento' => $evento, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                        Mail::to($user->email)->send(new EmailCertificado($user, 'coordenador/a da comissão Científica', $evento->nome, $pdf));
                    }
                }
                break;
            case Certificado::TIPO_ENUM['outras_comissoes']:
                foreach ($request->destinatarios as $i => $destinarioId) {
                    $qrcode = base64_encode(QrCode::generate($validacoes[$i]));
                    $certificado->usuarios()->attach($destinarioId, ['validacao' => $validacoes[$i], 'comissao_id' => $request->tipo_comissao_id]);
                    $user = User::find($destinarioId);
                    $comissao = TipoComissao::find($request->tipo_comissao_id);
                    $texto = $certificado->texto;
                    if ($request->boolean('sem_anexo')) {
                        $link = route('certificado.view', urlencode($validacoes[$i]));
                        Mail::to($user->email)->send(new EmailCertificadoSemAnexo($user, "membro da comissão {$comissao->nome}", $evento->nome, $link));
                    } else {
                        $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacoes[$i], 'certificado' => $certificado, 'user' => $user, 'cargo' => "membro da comissão {$comissao->nome}", 'evento' => $evento, 'comissao' => $comissao, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                        Mail::to($user->email)->send(new EmailCertificado($user, "membro da comissão {$comissao->nome}", $evento->nome, $pdf));
                    }
                }
                break;
            case Certificado::TIPO_ENUM['inscrito_atividade']:
                foreach ($request->destinatarios as $i => $destinarioId) {
                    $qrcode = base64_encode(QrCode::generate($validacoes[$i]));
                    $certificado->usuarios()->attach($destinarioId, ['validacao' => $validacoes[$i], 'atividade_id' => $request->atividades[$i]]);
                    $user = User::find($destinarioId);
                    $atividade = Atividade::find($request->atividades[$i]);
                    $texto = $certificado->texto;
                    if ($request->boolean('sem_anexo')) {
                        $link = route('certificado.view', urlencode($validacoes[$i]));
                        Mail::to($user->email)->send(new EmailCertificadoSemAnexo($user, "inscrito na atividade {$atividade->titulo}", $evento->nome, $link));
                    } else {
                        $pdf = Pdf::loadView('coordenador.certificado.certificado_preenchivel', ['texto' => $texto, 'qrcode' => $qrcode, 'validacao' => $validacoes[$i], 'certificado' => $certificado, 'user' => $user, 'cargo' => "inscrito na atividade {$atividade->titulo}", 'evento' => $evento, 'atividade' => $atividade, 'dataHoje' => $certificado->data->isoFormat('LL'), 'now' => now()->isoFormat('LL')])->setPaper('a4', 'landscape');
                        Mail::to($user->email)->send(new EmailCertificado($user, "inscrito na atividade {$atividade->titulo}", $evento->nome, $pdf));
                    }
                }
                break;
        }

        return redirect(route('coord.emitirCertificado', ['eventoId' => $evento->id]))->with(['success' => 'Certificados enviados com sucesso.']);
    }

    public function listarEmissoes(Certificado $certificado)
    {
        $evento = $certificado->evento;
        $usuarios = $certificado->usuarios;
        $comissao = null;
        $palestras = null;
        $trabalhos = null;
        $atividades = null;
        $tipos = Certificado::TIPO_ENUM;
        switch ($certificado->tipo) {
            case Certificado::TIPO_ENUM['apresentador']:
                $trabalhos = Trabalho::find($certificado->usuarios->pluck('pivot.trabalho_id'));
                break;
            case Certificado::TIPO_ENUM['inscrito_atividade']:
                $atividades = Atividade::find($certificado->usuarios->pluck('pivot.atividade_id'));
                break;
            case Certificado::TIPO_ENUM['expositor']:
                $palestras = Palestra::find($certificado->usuarios->pluck('pivot.palestra_id'));
                $usuarios = $certificado->usuariosPalestrantes;
                break;
            case Certificado::TIPO_ENUM['outras_comissoes']:
                $usuario = $certificado->usuarios->first();
                if ($usuario) {
                    $comissao = TipoComissao::find($usuario->pivot->comissao_id);
                }
                break;
            default:
                break;
        }

        return view('coordenador.certificado.emissoes', compact('evento', 'usuarios', 'certificado', 'comissao', 'palestras', 'trabalhos', 'tipos', 'atividades'));
    }

    public function validar(Request $request)
    {
        $certificado_user = DB::table('certificado_user')->where([
            ['validacao', '=', $request['hash']],
            ['valido', '=', true],
        ])->first();
        return $this->gerar_pdf($certificado_user);
    }

    private function gerar_pdf($certificado_user)
    {
        if ($certificado_user) {
            $certificado = Certificado::find($certificado_user->certificado_id);
            $evento = $certificado->evento;
            switch ($certificado->tipo) {
                case Certificado::TIPO_ENUM['apresentador']:
                    return $this->gerar_pdf_certificado($certificado, $certificado_user->user_id, $certificado_user->trabalho_id, $evento);
                case Certificado::TIPO_ENUM['expositor']:
                    return $this->gerar_pdf_certificado($certificado, $certificado_user->user_id, $certificado_user->palestra_id, $evento);
                case Certificado::TIPO_ENUM['outras_comissoes']:
                    return $this->gerar_pdf_certificado($certificado, $certificado_user->user_id, $certificado_user->comissao_id, $evento);
                default:
                    return $this->gerar_pdf_certificado($certificado, $certificado_user->user_id, 0, $evento);
            }
        } else {
            return redirect()->back()->withErrors(['hash' => 'Não existe certificado válido para esse código de verificação.']);
        }
    }

    public function verificar($hash)
    {
        $certificado_user = DB::table('certificado_user')->where([
            ['validacao', '=', urldecode($hash)],
            ['valido', '=', true],
        ])->first();
        return $this->gerar_pdf($certificado_user);
    }

    public function deletarEmissao(Request $request)
    {
        $request->validate(['certificado_user' => 'required']);
        $evento = Evento::find($request->evento);
        $this->authorize('isCoordenadorOrCoordenadorDaComissaoOrganizadora', $evento);
        DB::table('certificado_user')->where([
            ['id', '=', $request->certificado_user],
        ])->delete();

        return redirect()->back()->with('message', 'Emissão do certificado deletada com sucesso!');
    }
}
