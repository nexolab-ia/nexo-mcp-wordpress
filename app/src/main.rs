//! Verificador de escritorio de Nexo MCP.
//!
//! Complemento del plugin de WordPress (carpeta `nexo-mcp/`): comprueba desde
//! fuera que un sitio con Nexo MCP responda como lo hará un cliente de IA
//! (endpoint MCP protegido, descubrimiento OAuth, PKCE S256 y registro
//! dinámico de clientes) y muestra cómo conectar Claude Code y Cursor.
//!
//! `--screenshot <archivo.png>` abre la ventana, dibuja unos 120 cuadros,
//! guarda una imagen de la ventana y termina.

use eframe::egui;
use egui::{Color32, CornerRadius, FontId, Pos2, RichText, Stroke, Vec2};
use std::sync::{Arc, Mutex};

const BG: Color32 = Color32::from_rgb(0xf3, 0xf4, 0xf6);
const CARD: Color32 = Color32::WHITE;
const INK: Color32 = Color32::from_rgb(0x12, 0x12, 0x12);
const MUTED: Color32 = Color32::from_rgb(0x6e, 0x6e, 0x6e);
const BORDER: Color32 = Color32::from_rgb(0xe5, 0xe7, 0xeb);
const BAND: Color32 = Color32::from_rgb(0x0f, 0x17, 0x2a);
const CYAN: Color32 = Color32::from_rgb(0x22, 0xd3, 0xee);
const BLUE: Color32 = Color32::from_rgb(0x3b, 0x82, 0xf6);
const INDIGO: Color32 = Color32::from_rgb(0x63, 0x66, 0xf1);
const TEAL: Color32 = Color32::from_rgb(0x14, 0xb8, 0xa6);
const OK: Color32 = Color32::from_rgb(0x10, 0xb9, 0x81);
const WARN: Color32 = Color32::from_rgb(0xf5, 0x9e, 0x0b);
const FAIL: Color32 = Color32::from_rgb(0xdc, 0x26, 0x26);
const IDLE: Color32 = Color32::from_rgb(0xc4, 0xc8, 0xcf);

#[derive(Clone, Copy, PartialEq)]
enum Status {
    Pending,
    Ok,
    Warn,
    Fail,
}

#[derive(Clone)]
struct Check {
    name: &'static str,
    status: Status,
    detail: String,
}

fn pending_checks() -> Vec<Check> {
    [
        "Endpoint MCP protegido",
        "Recurso protegido (RFC 9728)",
        "Servidor de autorización (RFC 8414)",
        "PKCE con S256",
        "Registro dinámico de clientes",
        "Perfil de acceso anunciado",
        "Conexión segura (HTTPS)",
    ]
    .into_iter()
    .map(|name| Check {
        name,
        status: Status::Pending,
        detail: "Sin verificar todavía.".to_owned(),
    })
    .collect()
}

#[derive(Default)]
struct Shared {
    checks: Vec<Check>,
    running: bool,
    finished: bool,
}

struct Screenshot {
    path: String,
    frames: u32,
    requested: bool,
    image: Arc<Mutex<Option<Arc<egui::ColorImage>>>>,
}

struct App {
    site: String,
    shared: Arc<Mutex<Shared>>,
    shot: Option<Screenshot>,
    copied: Option<(String, f64)>,
}

impl App {
    fn new(shot: Option<String>) -> Self {
        Self {
            site: "http://localhost:8080".to_owned(),
            shared: Arc::new(Mutex::new(Shared {
                checks: pending_checks(),
                ..Default::default()
            })),
            shot: shot.map(|path| Screenshot {
                path,
                frames: 0,
                requested: false,
                image: Arc::new(Mutex::new(None)),
            }),
            copied: None,
        }
    }

    fn endpoint(&self) -> String {
        format!("{}/wp-json/nexo-mcp/v1/mcp", self.site.trim().trim_end_matches('/'))
    }

    fn start_checks(&self, ctx: &egui::Context) {
        let site = self.site.trim().trim_end_matches('/').to_owned();
        let shared = Arc::clone(&self.shared);
        {
            let mut s = shared.lock().unwrap();
            if s.running {
                return;
            }
            s.running = true;
            s.finished = false;
            s.checks = pending_checks();
        }
        let ctx = ctx.clone();
        std::thread::spawn(move || {
            run_checks(&site, &shared, &ctx);
            let mut s = shared.lock().unwrap();
            s.running = false;
            s.finished = true;
            ctx.request_repaint();
        });
    }
}

// ------------------------------------------------------------------ Revisión

fn set(shared: &Arc<Mutex<Shared>>, ctx: &egui::Context, i: usize, status: Status, detail: impl Into<String>) {
    let mut s = shared.lock().unwrap();
    if let Some(c) = s.checks.get_mut(i) {
        c.status = status;
        c.detail = detail.into();
    }
    ctx.request_repaint();
}

fn fail_rest(shared: &Arc<Mutex<Shared>>, ctx: &egui::Context, from: usize, why: &str) {
    for i in from..6 {
        set(shared, ctx, i, Status::Fail, why);
    }
}

fn agent() -> ureq::Agent {
    ureq::AgentBuilder::new()
        .timeout(std::time::Duration::from_secs(10))
        .redirects(0)
        .build()
}

fn get_json(agent: &ureq::Agent, url: &str) -> Result<serde_json::Value, String> {
    match agent.get(url).set("Accept", "application/json").call() {
        Ok(resp) => resp
            .into_string()
            .map_err(|e| e.to_string())
            .and_then(|body| serde_json::from_str(&body).map_err(|_| "La respuesta no es JSON.".to_owned())),
        Err(ureq::Error::Status(code, _)) => Err(format!("Respondió {code}.")),
        Err(e) => Err(format!("Sin respuesta: {e}")),
    }
}

fn run_checks(site: &str, shared: &Arc<Mutex<Shared>>, ctx: &egui::Context) {
    let agent = agent();
    let endpoint = format!("{site}/wp-json/nexo-mcp/v1/mcp");

    // 7. HTTPS (no depende de la red).
    let host_local = site.contains("://localhost") || site.contains("://127.0.0.1") || site.contains(".local") || site.contains(".test");
    if site.starts_with("https://") {
        set(shared, ctx, 6, Status::Ok, "El sitio usa HTTPS.");
    } else if host_local {
        set(shared, ctx, 6, Status::Warn, "HTTP sólo se admite en un entorno local de pruebas.");
    } else {
        set(shared, ctx, 6, Status::Fail, "Sin HTTPS el plugin rechaza las conexiones remotas.");
    }

    // 1. Endpoint protegido con desafío OAuth.
    let challenge = match agent
        .post(&endpoint)
        .set("Content-Type", "application/json")
        .send_string("{}")
    {
        Err(ureq::Error::Status(401, resp)) => resp.header("WWW-Authenticate").unwrap_or("").to_owned(),
        Err(ureq::Error::Status(404, _)) => {
            set(shared, ctx, 0, Status::Fail, "404: Nexo MCP no está activo en este sitio o los enlaces permanentes están apagados.");
            fail_rest(shared, ctx, 1, "No se pudo continuar sin el endpoint.");
            return;
        }
        Err(ureq::Error::Status(code, _)) => {
            set(shared, ctx, 0, Status::Fail, format!("Respondió {code} en vez de pedir autorización (401)."));
            fail_rest(shared, ctx, 1, "No se pudo continuar sin el endpoint.");
            return;
        }
        Err(e) => {
            set(shared, ctx, 0, Status::Fail, format!("El sitio no responde: {e}"));
            fail_rest(shared, ctx, 1, "No se pudo continuar sin conexión.");
            return;
        }
        Ok(resp) => {
            set(shared, ctx, 0, Status::Fail, format!("Respondió {} sin pedir credenciales: revisa el sitio.", resp.status()));
            fail_rest(shared, ctx, 1, "No se pudo continuar.");
            return;
        }
    };
    let Some(prm_url) = challenge
        .split("resource_metadata=\"")
        .nth(1)
        .and_then(|rest| rest.split('"').next())
        .map(str::to_owned)
    else {
        set(shared, ctx, 0, Status::Warn, "Pide autorización (401) pero sin el enlace de descubrimiento.");
        fail_rest(shared, ctx, 1, "Falta el documento de descubrimiento.");
        return;
    };
    set(shared, ctx, 0, Status::Ok, "Responde 401 y anuncia dónde autorizarse.");

    // 2. Recurso protegido.
    let prm = match get_json(&agent, &prm_url) {
        Ok(v) => v,
        Err(e) => {
            set(shared, ctx, 1, Status::Fail, format!("{e} Tu servidor puede estar bloqueando /.well-known/."));
            fail_rest(shared, ctx, 2, "Falta el documento de recurso protegido.");
            return;
        }
    };
    let issuer = prm["authorization_servers"][0].as_str().unwrap_or("").to_owned();
    if issuer.is_empty() {
        set(shared, ctx, 1, Status::Fail, "El documento no nombra un servidor de autorización.");
        fail_rest(shared, ctx, 2, "Sin servidor de autorización.");
        return;
    }
    set(shared, ctx, 1, Status::Ok, format!("Recurso: {}", prm["resource"].as_str().unwrap_or("?")));

    // 3. Servidor de autorización.
    let meta = match get_json(&agent, &format!("{issuer}/.well-known/oauth-authorization-server")) {
        Ok(v) => v,
        Err(e) => {
            set(shared, ctx, 2, Status::Fail, e);
            fail_rest(shared, ctx, 3, "Sin metadatos del servidor de autorización.");
            return;
        }
    };
    set(
        shared,
        ctx,
        2,
        Status::Ok,
        format!("Autorización en {}", meta["authorization_endpoint"].as_str().unwrap_or("?")),
    );

    // 4. PKCE.
    let pkce = meta["code_challenge_methods_supported"]
        .as_array()
        .is_some_and(|m| m.iter().any(|v| v == "S256"));
    if pkce {
        set(shared, ctx, 3, Status::Ok, "Exige PKCE con S256 (OAuth 2.1).");
    } else {
        set(shared, ctx, 3, Status::Fail, "No anuncia PKCE S256.");
    }

    // 5. Registro dinámico.
    match meta["registration_endpoint"].as_str() {
        Some(url) => set(shared, ctx, 4, Status::Ok, format!("Los clientes se registran solos en {url}")),
        None => set(shared, ctx, 4, Status::Warn, "Sin registro dinámico: algunos clientes no podrán conectarse."),
    }

    // 6. Perfil de acceso.
    let scopes: Vec<String> = prm["scopes_supported"]
        .as_array()
        .map(|a| a.iter().filter_map(|v| v.as_str().map(str::to_owned)).collect())
        .unwrap_or_default();
    if scopes.iter().any(|s| s == "mcp:write") {
        set(shared, ctx, 5, Status::Ok, format!("«Revisar y editar»: {}", scopes.join(" ")));
    } else {
        set(shared, ctx, 5, Status::Ok, format!("«Solo revisar» (seguro por defecto): {}", scopes.join(" ")));
    }
}

// ----------------------------------------------------------------- Interfaz

fn card<R>(ui: &mut egui::Ui, add: impl FnOnce(&mut egui::Ui) -> R) -> R {
    egui::Frame::new()
        .fill(CARD)
        .stroke(Stroke::new(1.0, BORDER))
        .corner_radius(CornerRadius::same(14))
        .inner_margin(egui::Margin::same(18))
        .show(ui, |ui| {
            ui.set_width(ui.available_width());
            add(ui)
        })
        .inner
}

fn eyebrow(ui: &mut egui::Ui, text: &str) {
    ui.label(RichText::new(text.to_uppercase()).size(11.0).color(MUTED).strong());
}

fn draw_logo(painter: &egui::Painter, origin: Pos2, scale: f32) {
    let p = |x: f32, y: f32| origin + Vec2::new(x * scale, y * scale);
    let pts = [p(0.0, 16.0), p(0.0, 0.0), p(16.0, 16.0), p(16.0, 0.0)];
    let colors = [CYAN, INDIGO, TEAL];
    for i in 0..3 {
        painter.line_segment([pts[i], pts[i + 1]], Stroke::new(3.2 * scale / 1.6, colors[i]));
    }
    painter.circle_filled(pts[1], 3.4 * scale / 1.6, Color32::WHITE);
    painter.circle_filled(pts[2], 3.4 * scale / 1.6, Color32::WHITE);
    painter.circle_filled(pts[0], 2.4 * scale / 1.6, CYAN);
    painter.circle_filled(pts[3], 2.4 * scale / 1.6, TEAL);
}

fn header(ui: &mut egui::Ui) {
    let (rect, _) = ui.allocate_exact_size(Vec2::new(ui.available_width(), 92.0), egui::Sense::hover());
    let painter = ui.painter_at(rect);
    painter.rect_filled(rect, CornerRadius::same(16), BAND);
    // Franja con el degradado de la marca.
    let stripe = egui::Rect::from_min_max(Pos2::new(rect.left() + 6.0, rect.bottom() - 4.0), Pos2::new(rect.right() - 6.0, rect.bottom()));
    let steps = 60;
    let stops = [CYAN, BLUE, INDIGO, TEAL];
    for i in 0..steps {
        let t = i as f32 / steps as f32;
        let seg = (t * 3.0).min(2.999);
        let k = seg.floor() as usize;
        let c = lerp(stops[k], stops[k + 1], seg - k as f32);
        let x0 = stripe.left() + stripe.width() * t;
        let x1 = stripe.left() + stripe.width() * (i + 1) as f32 / steps as f32 + 0.5;
        painter.rect_filled(egui::Rect::from_min_max(Pos2::new(x0, stripe.top()), Pos2::new(x1, stripe.bottom())), 0.0, c);
    }
    draw_logo(&painter, Pos2::new(rect.left() + 30.0, rect.center().y - 13.0), 1.6);
    painter.text(
        Pos2::new(rect.left() + 70.0, rect.center().y),
        egui::Align2::LEFT_CENTER,
        "nexo",
        FontId::proportional(24.0),
        Color32::WHITE,
    );
    painter.text(
        Pos2::new(rect.left() + 128.0, rect.center().y),
        egui::Align2::LEFT_CENTER,
        "MCP · Verificador",
        FontId::proportional(24.0),
        Color32::from_rgb(0x9f, 0xb3, 0xc8),
    );
    painter.text(
        Pos2::new(rect.right() - 28.0, rect.center().y - 9.0),
        egui::Align2::RIGHT_CENTER,
        "COMPLEMENTO DEL PLUGIN",
        FontId::proportional(10.5),
        Color32::from_rgb(0x9f, 0xb3, 0xc8),
    );
    painter.text(
        Pos2::new(rect.right() - 28.0, rect.center().y + 10.0),
        egui::Align2::RIGHT_CENTER,
        "v1.0.0",
        FontId::proportional(17.0),
        Color32::WHITE,
    );
}

fn lerp(a: Color32, b: Color32, t: f32) -> Color32 {
    let m = |x: u8, y: u8| (x as f32 + (y as f32 - x as f32) * t).round() as u8;
    Color32::from_rgb(m(a.r(), b.r()), m(a.g(), b.g()), m(a.b(), b.b()))
}

fn status_dot(ui: &mut egui::Ui, status: Status) {
    let (rect, _) = ui.allocate_exact_size(Vec2::splat(14.0), egui::Sense::hover());
    let color = match status {
        Status::Pending => IDLE,
        Status::Ok => OK,
        Status::Warn => WARN,
        Status::Fail => FAIL,
    };
    ui.painter().circle_filled(rect.center(), 5.0, color);
}

impl App {
    fn recipe(&mut self, ui: &mut egui::Ui, title: &str, intro: &str, code: String) {
        ui.label(RichText::new(title).strong().size(14.5).color(INK));
        ui.label(RichText::new(intro).size(12.5).color(MUTED));
        ui.horizontal_top(|ui| {
            egui::Frame::new()
                .fill(Color32::from_rgb(0xf8, 0xfa, 0xfc))
                .stroke(Stroke::new(1.0, BORDER))
                .corner_radius(CornerRadius::same(8))
                .inner_margin(egui::Margin::same(10))
                .show(ui, |ui| {
                    let w = (ui.available_width() - 90.0).max(120.0);
                    ui.set_width(w);
                    ui.set_max_width(w);
                    ui.add(egui::Label::new(RichText::new(&code).monospace().size(12.0).color(INK)).wrap());
                });
            let done = self.copied.as_ref().is_some_and(|(c, _)| *c == code);
            if ui.button(if done { "Copiado" } else { "Copiar" }).clicked() {
                ui.ctx().copy_text(code.clone());
                self.copied = Some((code.clone(), ui.input(|i| i.time)));
            }
        });
        ui.add_space(10.0);
    }
}

impl eframe::App for App {
    fn ui(&mut self, ui: &mut egui::Ui, _frame: &mut eframe::Frame) {
        let ctx = ui.ctx().clone();
        let ctx = &ctx;

        // Modo captura: unos 120 cuadros, foto de la ventana y salir.
        if let Some(shot) = &mut self.shot {
            shot.frames += 1;
            ctx.request_repaint();
            if shot.frames >= 120 && !shot.requested {
                shot.requested = true;
                let slot = Arc::clone(&shot.image);
                let repaint = ctx.clone();
                ctx.send_viewport_cmd(egui::ViewportCommand::Screenshot(egui::ScreenshotCallback::new(
                    move |image| {
                        *slot.lock().unwrap() = Some(image);
                        repaint.request_repaint();
                    },
                )));
            }
            let image = shot.image.lock().unwrap().take();
            if let Some(image) = image {
                let [w, h] = image.size;
                let bytes: Vec<u8> = image.pixels.iter().flat_map(|p| p.to_array()).collect();
                match image::RgbaImage::from_raw(w as u32, h as u32, bytes) {
                    Some(buf) => {
                        if let Err(e) = buf.save(&shot.path) {
                            eprintln!("No se pudo guardar la captura: {e}");
                        }
                    }
                    None => eprintln!("La captura llegó vacía."),
                }
                ctx.send_viewport_cmd(egui::ViewportCommand::Close);
            }
        }

        let (checks, running, finished) = {
            let s = self.shared.lock().unwrap();
            (s.checks.clone(), s.running, s.finished)
        };

        egui::CentralPanel::default()
            .frame(egui::Frame::new().fill(BG).inner_margin(egui::Margin::same(22)))
            .show(ui, |ui| {
                header(ui);
                ui.add_space(16.0);

                let width = ui.available_width();
                let left_w = (width * 0.56).floor();
                ui.horizontal_top(|ui| {
                    ui.vertical(|ui| {
                        ui.set_width(left_w);
                        card(ui, |ui| {
                            eyebrow(ui, "Sitio WordPress");
                            ui.label(RichText::new("¿Responde tu sitio como espera una IA?").size(19.0).strong().color(INK));
                            ui.add_space(4.0);
                            ui.label(
                                RichText::new("Escribe la dirección del sitio que tiene Nexo MCP instalado y pulsa Verificar. Se hace lo mismo que hará Claude o Cursor antes de pedirte autorización.")
                                    .size(13.0)
                                    .color(MUTED),
                            );
                            ui.add_space(10.0);
                            ui.horizontal(|ui| {
                                let edit = egui::TextEdit::singleline(&mut self.site)
                                    .desired_width(ui.available_width() - 120.0)
                                    .font(FontId::proportional(15.0))
                                    .margin(egui::Margin::symmetric(10, 8));
                                let resp = ui.add(edit);
                                let go = ui.add_enabled(
                                    !running,
                                    egui::Button::new(RichText::new(if running { "Verificando…" } else { "Verificar" }).color(Color32::WHITE).size(14.0))
                                        .fill(INK)
                                        .min_size(Vec2::new(108.0, 36.0))
                                        .corner_radius(CornerRadius::same(8)),
                                );
                                if go.clicked() || (resp.lost_focus() && ui.input(|i| i.key_pressed(egui::Key::Enter))) {
                                    self.start_checks(ctx);
                                }
                            });
                        });
                        ui.add_space(14.0);
                        card(ui, |ui| {
                            ui.horizontal(|ui| {
                                eyebrow(ui, "Comprobaciones");
                                ui.with_layout(egui::Layout::right_to_left(egui::Align::Center), |ui| {
                                    let ok = checks.iter().filter(|c| c.status == Status::Ok).count();
                                    let text = if finished {
                                        format!("{ok} de {} correctas", checks.len())
                                    } else if running {
                                        "En curso".to_owned()
                                    } else {
                                        "Pendiente".to_owned()
                                    };
                                    ui.label(RichText::new(text).size(12.0).color(MUTED));
                                });
                            });
                            ui.add_space(6.0);
                            for (i, check) in checks.iter().enumerate() {
                                if i > 0 {
                                    ui.add(egui::Separator::default().spacing(10.0));
                                }
                                ui.horizontal_top(|ui| {
                                    status_dot(ui, check.status);
                                    ui.vertical(|ui| {
                                        ui.label(RichText::new(check.name).size(14.0).strong().color(INK));
                                        ui.label(RichText::new(&check.detail).size(12.5).color(MUTED));
                                    });
                                });
                            }
                        });
                    });
                    ui.add_space(16.0);
                    let right_w = (width - left_w - 16.0).max(260.0);
                    ui.vertical(|ui| {
                        ui.set_width(right_w);
                        ui.set_max_width(right_w);
                        card(ui, |ui| {
                            eyebrow(ui, "Conectar tu IA");
                            ui.label(RichText::new("Sólo se copia la dirección").size(19.0).strong().color(INK));
                            ui.add_space(8.0);
                            let endpoint = self.endpoint();
                            self.recipe(
                                ui,
                                "Claude Code",
                                "En la terminal; luego /mcp → Authenticate.",
                                format!("claude mcp add --transport http nexo-wordpress {endpoint}"),
                            );
                            self.recipe(
                                ui,
                                "Cursor",
                                "En ~/.cursor/mcp.json:",
                                format!("{{ \"mcpServers\": {{ \"nexo-wordpress\": {{ \"url\": \"{endpoint}\" }} }} }}"),
                            );
                            ui.label(
                                RichText::new("El navegador abrirá WordPress para que apruebes el acceso. Cada conexión queda en Nexo MCP › Accesos (OAuth).")
                                    .size(12.5)
                                    .color(MUTED),
                            );
                        });
                        ui.add_space(14.0);
                        card(ui, |ui| {
                            eyebrow(ui, "Seguro por defecto");
                            for (title, text) in [
                                ("Solo revisar", "Un sitio nuevo sólo deja leer; editar pide escribir «lectura»."),
                                ("Alcances por conexión", "Cada IA usa sólo los permisos que le concediste."),
                                ("Copia de trabajo", "Lo publicado se edita primero en un borrador."),
                                ("Irreversible = confirmado", "Borrar o restaurar exige una palabra exacta."),
                            ] {
                                ui.add_space(4.0);
                                ui.horizontal_top(|ui| {
                                    status_dot(ui, Status::Ok);
                                    ui.vertical(|ui| {
                                        ui.label(RichText::new(title).size(13.5).strong().color(INK));
                                        ui.label(RichText::new(text).size(12.5).color(MUTED));
                                    });
                                });
                            }
                        });
                    });
                });
            });

        if let Some((_, at)) = &self.copied {
            if ctx.input(|i| i.time) - at > 2.0 {
                self.copied = None;
            } else {
                ctx.request_repaint();
            }
        }
    }
}

fn main() -> eframe::Result<()> {
    let args: Vec<String> = std::env::args().collect();
    let shot = args
        .iter()
        .position(|a| a == "--screenshot")
        .and_then(|i| args.get(i + 1).cloned());
    let auto_check = shot.is_none() && !args.iter().any(|a| a == "--sin-verificar");

    if let Some(path) = &shot {
        if let Some(dir) = std::path::Path::new(path).parent() {
            if !dir.as_os_str().is_empty() {
                let _ = std::fs::create_dir_all(dir);
            }
        }
    }

    let options = eframe::NativeOptions {
        viewport: egui::ViewportBuilder::default()
            .with_title("Nexo MCP · Verificador")
            .with_inner_size([1180.0, 760.0])
            .with_min_inner_size([900.0, 620.0]),
        ..Default::default()
    };

    eframe::run_native(
        "Nexo MCP · Verificador",
        options,
        Box::new(move |cc| {
            cc.egui_ctx.set_visuals(egui::Visuals::light());
            let app = App::new(shot);
            if auto_check {
                app.start_checks(&cc.egui_ctx);
            }
            Ok(Box::new(app))
        }),
    )
}
