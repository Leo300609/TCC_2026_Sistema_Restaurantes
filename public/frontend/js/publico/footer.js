
const modalOverlay = document.getElementById("modal-overlay");
const modalTitulo = document.getElementById("modal-titulo");
const modalDescricao = document.getElementById("modal-descricao");
const modalCaixa = document.querySelector(".modal-caixa");

const botaoFechar = document.querySelector(".modal-fechar");
const botaoConfirmar = document.querySelector(".modal-confirmar");

let ultimoBotao = null;

const conteudosFooter = {
    termos: {
        titulo: "Termos de Uso",
        icone: "fa-file-contract",
        descricao: `
            <p>Bem-vindo à XTEC. Estes termos apresentam as condições
            gerais de utilização da plataforma de gestão para restaurantes.</p>

            <h3>Utilização da plataforma</h3>
            <p>O usuário deve utilizar o sistema de maneira responsável,
            respeitando as funcionalidades disponíveis e as regras de acesso.</p>

            <h3>Responsabilidades</h3>
            <p>O usuário é responsável pela confidencialidade de suas credenciais
            e pelas ações realizadas por sua conta, observadas as responsabilidades
            aplicáveis a cada situação.</p>

            <h3>Disponibilidade</h3>
            <p>Atualizações, manutenções e melhorias poderão ser realizadas
            para manter e aprimorar o funcionamento do sistema.</p>

           
        `
    },

    privacidade: {
        titulo: "Política de Privacidade",
        icone: "fa-user-shield",
        descricao: `
            <p>A XTEC busca tratar as informações dos usuários com
            responsabilidade e transparência.</p>

            <h3>Dados tratados</h3>
            <p>Dependendo das funcionalidades utilizadas, poderão ser tratados
            dados de cadastro, informações de acesso e dados operacionais
            inseridos na plataforma.</p>

            <h3>Finalidade</h3>
            <p>As informações poderão ser utilizadas para disponibilizar
            funcionalidades, manter a segurança, prestar suporte e melhorar
            os serviços, conforme as finalidades efetivamente adotadas.</p>

            <h3>Proteção e compartilhamento</h3>
            <p>O tratamento e o eventual compartilhamento de dados devem
            seguir as finalidades informadas, as bases legais aplicáveis
            e as medidas de proteção pertinentes.</p>

            <p>A política definitiva deve identificar os dados coletados,
            os prazos de retenção, os destinatários e os canais para
            exercício dos direitos dos titulares.</p>
        `
    },

    diretrizes: {
        titulo: "Diretrizes de Operação",
        icone: "fa-gears",
        descricao: `
            <p>As diretrizes da XTEC orientam o uso organizado e responsável
            das ferramentas de gestão do restaurante.</p>

            <h3>Organização das informações</h3>
            <p>Mantenha os registros de vendas, estoque, custos e demais
            informações operacionais atualizados sempre que possível.</p>

            <h3>Controle de acesso</h3>
            <p>Utilize contas individuais e conceda permissões compatíveis
            com as responsabilidades de cada integrante da equipe.</p>

            <h3>Conferência de dados</h3>
            <p>Revise os registros e indicadores antes de utilizá-los
            para tomar decisões importantes sobre a operação.</p>
        `
    },

    seguranca: {
        titulo: "Segurança & LGPD",
        icone: "fa-shield-halved",
        descricao: `
            <p>A segurança digital é essencial para proteger os dados
            e manter a confiabilidade das operações.</p>

            <h3>Boas práticas de segurança</h3>
            <ul>
                <li>Utilize senhas fortes e não compartilhe credenciais.</li>
                <li>Restrinja o acesso conforme a função de cada usuário.</li>
                <li>Mantenha os dispositivos e programas atualizados.</li>
                <li>Revise os acessos e comunique possíveis incidentes.</li>
            </ul>

            <h3>Lei Geral de Proteção de Dados</h3>
            <p>A LGPD (Lei nº 13.709/2018) estabelece regras para o tratamento
            de dados pessoais. A aplicação dessas regras depende das atividades
            realizadas e das responsabilidades de cada agente de tratamento.</p>

            <p>As medidas e os procedimentos efetivamente adotados pela XTEC
            devem ser descritos na política de privacidade e na documentação
            de segurança da plataforma.</p>
        `
    },

    suporte: {
        titulo: "Central de Suporte",
        icone: "fa-headset",
        descricao: `
            <p>Precisa de ajuda com a XTEC? O suporte pode orientar você
            sobre o uso das funcionalidades e a resolução de dificuldades.</p>

            <h3>Como solicitar ajuda</h3>
            <p>Informe a funcionalidade utilizada, descreva o problema
            encontrado e, se necessário, forneça uma captura de tela
            sem expor senhas ou dados pessoais desnecessários.</p>

            <h3>Atendimento</h3>
            <p>+11 99452-5983  (número do suporte)</p>

            <p>O prazo de resposta deve ser informado conforme a
            disponibilidade real do serviço.</p>
        `
    }
};

function abrirModal(tipo, botao) {
    const conteudo = conteudosFooter[tipo];

    if (!conteudo) return;

    ultimoBotao = botao;

    modalTitulo.textContent = conteudo.titulo;
    modalDescricao.innerHTML = conteudo.descricao;

    const icone = modalCaixa.querySelector(".modal-icone i");
    icone.className = `fa-solid ${conteudo.icone}`;

    modalOverlay.classList.add("ativo");
    modalOverlay.setAttribute("aria-hidden", "false");

    document.body.style.overflow = "hidden";

    botaoFechar.focus();
}

function fecharModal() {
    modalOverlay.classList.remove("ativo");
    modalOverlay.setAttribute("aria-hidden", "true");

    document.body.style.overflow = "";

    if (ultimoBotao) {
        ultimoBotao.focus();
    }
}

document.querySelectorAll(".rodape-botoes").forEach(botao => {
    botao.addEventListener("click", () => {
        abrirModal(botao.dataset.modal, botao);
    });
});

botaoFechar.addEventListener("click", fecharModal);
botaoConfirmar.addEventListener("click", fecharModal);

modalOverlay.addEventListener("click", evento => {
    if (evento.target === modalOverlay) {
        fecharModal();
    }
});

document.addEventListener("keydown", evento => {
    if (
        evento.key === "Escape" &&
        modalOverlay.classList.contains("ativo")
    ) {
        fecharModal();
    }
});
