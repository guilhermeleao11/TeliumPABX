/*
 * Telium PABX no Kommo: o clique no telefone do contato vira ligação.
 *
 * O botão "Ligar pelo PABX" aparece ao clicar num telefone (contato, lead,
 * empresa e listas). O app manda à central o número e QUEM clicou (o id
 * do usuário no Kommo); a central sabe o ramal de cada usuário, toca o
 * ramal dele e, quando ele atende, disca o cliente.
 *
 * O registro da chamada e a gravação no histórico do contato não passam
 * por aqui: é a central que os manda ao Kommo, ao fim de cada ligação.
 *
 * A central é chamada direto do navegador (ela libera as telas do Kommo
 * por CORS). Se o navegador não alcançar a central, o pedido vai pelo
 * proxy do Kommo (crm_post), que a alcança pela internet.
 */
define(['jquery'], function ($) {
  'use strict';

  return function () {
    var self = this;

    function txt(chave) {
      var l = self.i18n('telium') || {};
      return l[chave] || chave;
    }

    function config() {
      var s = self.get_settings() || {};
      var url = String(s.pabx_url || '').trim().replace(/\/+$/, '');
      if (url && !/^https?:\/\//i.test(url)) url = 'https://' + url;
      return { url: url, chave: String(s.chave || '').trim() };
    }

    function aviso(texto) {
      try {
        AMOCRM.notifications.show_message({ header: txt('titulo'), text: texto });
      } catch (e) {
        window.alert(txt('titulo') + ': ' + texto);
      }
    }

    function usuarioId() {
      var s = self.system() || {};
      if (s.amouser_id) return s.amouser_id;
      try { return AMOCRM.constant('user').id; } catch (e) { return ''; }
    }

    // O nome do cartão aberto vai para o visor do ramal.
    function nomeDoCartao() {
      try {
        var m = AMOCRM.data.current_card && AMOCRM.data.current_card.model;
        return (m && (m.get('name') || m.get('contact[NAME]') || m.get('lead[NAME]'))) || '';
      } catch (e) {
        return '';
      }
    }

    function resposta(r) {
      if (r && r.ok) {
        aviso(r.mensagem || txt('ligando'));
      } else {
        aviso((r && r.erro) || txt('falhou'));
      }
    }

    function pelaProxy(c, dados) {
      self.crm_post(
        c.url + '/api/kommo/discar',
        $.extend({ token: c.chave }, dados),
        function (r) {
          if (typeof r === 'string') {
            try { r = JSON.parse(r); } catch (e) { r = null; }
          }
          resposta(r);
        },
        'json',
        function () { aviso(txt('sem_central')); }
      );
    }

    function ligar(telefone) {
      var c = config();
      if (!c.url || !c.chave) {
        aviso(txt('sem_config'));
        return;
      }
      var dados = { usuario_id: usuarioId(), telefone: String(telefone || ''), nome: nomeDoCartao() };

      fetch(c.url + '/api/kommo/discar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Api-Key': c.chave },
        body: JSON.stringify(dados)
      })
        .then(function (r) { return r.json(); })
        .then(resposta)
        .catch(function () { pelaProxy(c, dados); });
    }

    this.callbacks = {
      render: function () { return true; },
      init: function () {
        self.add_action('phone', function (data) {
          ligar(data && data.value);
        });
        return true;
      },
      bind_actions: function () { return true; },
      settings: function () { return true; },
      onSave: function () { return true; },
      destroy: function () {},
      contacts: { selected: function () {} },
      leads: { selected: function () {} },
      tasks: { selected: function () {} }
    };

    return this;
  };
});
