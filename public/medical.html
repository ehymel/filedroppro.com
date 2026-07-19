<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HIPAA Compliant Secure Patient File Drop & EHR Document Collection</title>
    <!-- Load Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Load Bootstrap Icons CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Google Font - Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --bs-body-font-family: 'Inter', sans-serif;
            --primary-navy: #0b1528;
            --accent-teal: #0d9488;
            --accent-sky: #0284c7;
            --secondary-dark: #1e293b;
            --light-slate: #f8fafc;
            --teal-glow: rgba(13, 148, 136, 0.2);
        }

        body {
            background-color: var(--light-slate);
            color: #334155;
            overflow-x: hidden;
        }

        /* Premium Clinical Gradients */
        .premium-teal-gradient {
            background: linear-gradient(135deg, #050b14 0%, #0c1c2e 100%);
        }

        .teal-glow {
            text-shadow: 0 0 15px var(--teal-glow);
        }

        .text-accent-teal {
            color: var(--accent-teal);
        }

        .text-accent-sky {
            color: var(--accent-sky);
        }

        .btn-accent-teal {
            background-color: var(--accent-teal);
            color: #fff;
            border: 1px solid var(--accent-teal);
            font-weight: 600;
            transition: all 0.2s ease-in-out;
        }

        .btn-accent-teal:hover {
            background-color: #0f766e;
            border-color: #0f766e;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(13, 148, 136, 0.4);
        }

        .btn-outline-sky {
            background-color: transparent;
            color: var(--accent-sky);
            border: 2px solid var(--accent-sky);
            font-weight: 600;
            transition: all 0.2s ease-in-out;
        }

        .btn-outline-sky:hover {
            background-color: var(--accent-sky);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
        }

        .hover-lift {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .hover-lift:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05) !important;
        }

        /* Interactive Patient Drop Simulator Styles */
        .simulator-box {
            background-color: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(12px);
            border-radius: 1rem;
            color: #f1f5f9;
        }

        .drop-zone-sim {
            border: 2px dashed rgba(13, 148, 136, 0.5);
            border-radius: 0.75rem;
            padding: 2.5rem 1.5rem;
            text-align: center;
            background: rgba(12, 28, 46, 0.4);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .drop-zone-sim:hover {
            border-color: var(--accent-teal);
            background: rgba(12, 28, 46, 0.6);
        }

        /* Timeline Graphics for UX */
        .step-pill {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background: rgba(13, 148, 136, 0.15);
            border: 1px solid var(--accent-teal);
            color: var(--accent-teal);
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Code-like details */
        .code-block {
            font-family: monospace;
            font-size: 0.85rem;
            background: #050b14;
            color: #2dd4bf;
            padding: 0.75rem;
            border-radius: 0.5rem;
            border: 1px solid rgba(255,255,255,0.05);
            white-space: pre-line;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-slate-950 py-3 border-bottom border-secondary-subtle premium-teal-gradient">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="#">
            <span class="p-2 bg-teal-950 rounded-3 me-2 d-inline-flex border border-teal-800">
                <i class="bi bi-shield-lock-fill text-accent-teal fs-4"></i>
            </span>
            <span class="fw-bold tracking-tight text-white fs-4">FileDrop <span class="text-accent-teal">Pro</span></span>
        </a>
        <button class="navbar-expand navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navContent">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse justify-content-end" id="navContent">
            <span class="navbar-text me-4 text-slate-300 d-none d-lg-inline">
                🔒 <span class="fw-semibold">HIPAA & HITECH Compliant</span> Secure Drop
            </span>
            <a href="#calculator" class="btn btn-outline-light btn-sm me-3 fw-semibold">Calculate Clinic Time Lost</a>
            <a href="/register" class="btn btn-accent-teal btn-sm px-4">Start 14-Day Free Trial</a>
        </div>
    </div>
</nav>

<section class="premium-teal-gradient text-white py-5 position-relative overflow-hidden">
    <div class="container py-lg-5">
        <div class="row align-items-center g-5">
            <div class="col-12 col-lg-6 text-center text-lg-start">
                <span class="badge text-uppercase bg-teal-950 border border-teal-800 text-accent-teal mb-3 px-3 py-2 fw-semibold">
                    HIPAA COMPLIANT & PHI ENCRYPTED ARCHITECTURE
                </span>
                <h1 class="display-4 fw-extrabold text-white mb-3">
                    Collect Private <br>
                    <span class="text-accent-teal teal-glow">Patient Intake Records</span>
                </h1>
                <p class="lead text-slate-300 mb-4">
                    Receiving patient intake files, insurance cards, or clinical histories over unsecured email violates federal HIPAA data rules. FileDrop Pro enables patients to securely drop files straight to your encrypted vault—with <strong>zero portal-login friction and total E2EE protection.</strong>
                </p>
                <div class="d-flex flex-column flex-sm-row justify-content-center justify-content-lg-start gap-3 mb-4">
                    <a href="/register" class="btn btn-accent-teal btn-lg px-4 py-3 shadow">
                        Start Free Trial
                    </a>
                    <a href="#how-it-works" class="btn btn-outline-sky btn-lg px-4 py-3">
                        <i class="bi bi-play-circle me-2"></i> Try Patient Simulator
                    </a>
                </div>
                <div class="d-flex align-items-center justify-content-center justify-content-lg-start gap-4 text-slate-400 small">
                    <span><i class="bi bi-credit-card-2-front text-accent-teal me-1"></i> No Credit Card Required for 14-Day Free Trial</span>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="simulator-box p-4 shadow-lg position-relative" id="interactive-simulator">
                    <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-3 mb-3">
                        <div class="d-flex align-items-center">
                            <span class="bg-danger rounded-circle d-inline-block me-1" style="width: 8px; height: 8px;"></span>
                            <span class="bg-warning rounded-circle d-inline-block me-1" style="width: 8px; height: 8px;"></span>
                            <span class="bg-success rounded-circle d-inline-block me-2" style="width: 8px; height: 8px;"></span>
                            <span class="badge bg-secondary text-slate-300 font-monospace small">https://filedroppro.com/drop/community-clinic</span>
                        </div>
                        <span class="badge bg-success-subtle text-success border border-success border-opacity-25">Patient-Safe Drop Box</span>
                    </div>

                    <div class="simulator-content">
                        <h4 class="h5 fw-bold mb-1">Secure Medical Document Drop</h4>
                        <p class="text-slate-400 small mb-3">Medical records and IDs are encrypted *locally* inside your browser. Not even our servers can decrypt them.</p>

                        <!-- Drop Box Simulation Drag Box -->
                        <div class="drop-zone-sim my-4" id="sim-drop-zone" onclick="triggerSimulateEncryption()">
                            <i class="bi bi-file-medical-fill text-accent-teal display-5 mb-2 d-block"></i>
                            <span class="fw-semibold d-block text-white mb-1">Click to Simulate Patient Intake Upload</span>
                            <span class="text-slate-400 small">Simulate dropping an intake PDF, insurance card image, or chart record here</span>
                        </div>

                        <!-- Live Cryptographic Animation Panel -->
                        <div class="code-area d-none" id="sim-crypto-panel">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-accent-teal small font-monospace"><i class="bi bi-shield-check me-1 animate-spin"></i> Local Patient E2EE Engine</span>
                                <span class="badge bg-info-subtle text-info font-monospace small" id="sim-step">Generating Crypt Key...</span>
                            </div>
                            <div class="code-block" id="sim-log">
                                Deriving unique clinical key parameters locally...
                            </div>
                            <div class="progress mt-3 bg-secondary" style="height: 6px;">
                                <div class="progress-bar bg-accent-teal" id="sim-progress" style="width: 10%;"></div>
                            </div>
                        </div>

                        <!-- Complete Badge -->
                        <div class="alert alert-success d-none mb-0 p-3 mt-3 d-flex align-items-center" id="sim-complete-panel">
                            <i class="bi bi-check-circle-fill text-success fs-3 me-3"></i>
                            <div>
                                <strong class="d-block">Zero-Knowledge Payload Complete!</strong>
                                <span class="small text-muted">The patient's chart file was scrambled using AES-GCM-256 before leaving their browser. Only authorized personnel inside your practice hold the private decryption keys.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="bg-white border-bottom border-top border-secondary-subtle py-4">
    <div class="container text-center">
        <p class="text-muted text-uppercase fw-bold tracking-wider fs-7 mb-4">PHI SAFEGUARDS FIREWALL FOR HEALTHCARE CLINICS & PRACTITIONERS</p>
        <div class="row align-items-center justify-content-center g-4 opacity-75">
            <div class="col-6 col-md-3">
                <h4 class="h5 fw-bold text-slate-800 m-0"><i class="bi bi-heart-pulse-fill text-accent-teal me-2"></i> HIPAA Compliant</h4>
            </div>
            <div class="col-6 col-md-3">
                <h4 class="h5 fw-bold text-slate-800 m-0"><i class="bi bi-journal-medical text-accent-teal me-2"></i> HITECH Safeguards</h4>
            </div>
            <div class="col-6 col-md-3">
                <h4 class="h5 fw-bold text-slate-800 m-0"><i class="bi bi-shield-fill-check text-accent-teal me-2"></i> Patient Privacy Safe</h4>
            </div>
            <div class="col-6 col-md-3">
                <h4 class="h5 fw-bold text-slate-800 m-0"><i class="bi bi-fingerprint text-accent-teal me-2"></i> AES-256 / RSA</h4>
            </div>
        </div>
    </div>
</section>

<section class="py-5 bg-white" id="how-it-works">
    <div class="container py-lg-5">
        <div class="text-center max-w-2xl mx-auto mb-5">
            <h2 class="display-6 fw-bold text-slate-900 mb-2">Why Standard Portals & Email Fail Your Practice</h2>
            <p class="lead text-muted">Intake coordinators lose hours every week manually chasing paperwork, dealing with locked portal accounts, and handling confused patients who forget their login passwords.</p>
        </div>

        <div class="row g-4 pt-4">
            <div class="col-12 col-md-4">
                <div class="card h-100 border border-secondary-subtle p-4 rounded-3 shadow-sm hover-lift">
                    <div class="step-pill mb-3">1</div>
                    <h3 class="h5 fw-bold text-slate-900">No Patient Password Stress</h3>
                    <p class="text-muted">Patients never need to register an account, remember logins, or download EHR apps. They click your private link and drop intake paperwork in seconds. This prevents onboarding friction and charts are completed faster.</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card h-100 border border-secondary-subtle p-4 rounded-3 shadow-sm hover-lift">
                    <div class="step-pill mb-3">2</div>
                    <h3 class="h5 fw-bold text-slate-900">Total HIPAA Liability Shield</h3>
                    <p class="text-muted">Under the HIPAA security rule, clinics are liable for PHI breaches. Because our backend cannot decrypt files, you possess a complete cryptographic liability shield. Your files are safe, even in a cloud data-center breach.</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card h-100 border border-secondary-subtle p-4 rounded-3 shadow-sm hover-lift">
                    <div class="step-pill mb-3">3</div>
                    <h3 class="h5 fw-bold text-slate-900">Safe Institutional Recovery</h3>
                    <p class="text-muted">If a clinic manager loses their password, patient charts aren't locked forever. Safe multi-user escrow ceremonies allow managing providers to recover clinical historical files internally without giving up HIPAA privacy.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5 bg-light border-top border-bottom" id="calculator">
    <div class="container py-lg-4">
        <div class="row align-items-center g-5">
            <div class="col-12 col-lg-5">
                <h2 class="display-6 fw-bold text-slate-900 mb-3">Calculate Your Clinic's Friction Costs</h2>
                <p class="text-muted">
                    Chasing patients for missing medical intake forms, explaining portal registration, and extracting medical charts from insecure email threads consumes valuable hours that medical administrative staff should spend focusing on patient care.
                </p>
                <p class="text-muted">
                    Input your average clinic metrics to calculate exactly how many staff administrative hours you reclaim and the overhead expenses you recover with friction-free patient drops.
                </p>
            </div>

            <div class="col-12 col-lg-7">
                <div class="card shadow border-0 p-4 rounded-3">
                    <h3 class="h5 fw-bold mb-4 border-bottom pb-3"><i class="bi bi-calculator-fill text-accent-teal me-2"></i> Patient Intake Friction Cost Calculator</h3>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="calc-patients" class="form-label fw-semibold text-slate-700">New Onboarding Patients / Month</label>
                            <input type="number" id="calc-patients" class="form-control" value="80" oninput="calculateROI()">
                            <div class="form-text">Patients requiring pre-visit intake or record submission.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="calc-hourly" class="form-label fw-semibold text-slate-700">Staff Administrative Cost ($/hr)</label>
                            <input type="number" id="calc-hourly" class="form-control" value="35" oninput="calculateROI()">
                            <div class="form-text">The estimated cost per hour of clinic staff time.</div>
                        </div>
                    </div>

                    <div class="bg-teal-950 bg-opacity-10 p-4 rounded-3 mt-4 text-center border border-teal-800 border-opacity-25">
                        <div class="row g-3">
                            <div class="col-12 col-sm-6 border-end">
                                <span class="text-muted d-block small text-uppercase fw-semibold">Staff Hours Saved / Year</span>
                                <span class="display-6 fw-bold text-slate-900" id="calc-hours-val">160 hrs</span>
                            </div>
                            <div class="col-12 col-sm-6">
                                <span class="text-muted d-block small text-uppercase fw-semibold">Annual Overhead Saved</span>
                                <span class="display-6 fw-bold text-accent-teal" id="calc-cash-val">$5,600</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 text-center">
                        <a href="/register" class="btn btn-accent-teal px-5 py-2.5 fw-semibold shadow-sm">
                            Reclaim Your Clinic's Time (Start Trial)
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-5 bg-white">
    <div class="container py-lg-5">
        <div class="text-center max-w-2xl mx-auto mb-5">
            <h2 class="display-6 fw-bold text-slate-900">Healthcare Compliance & Security FAQs</h2>
            <p class="text-muted">Answers regarding the HIPAA Privacy Rule, patient record security, and browser E2EE.</p>
        </div>

        <div class="row justify-content-center">
            <div class="col-12 col-lg-8">
                <div class="accordion accordion-flush" id="faqAccordion">
                    <div class="accordion-item py-2">
                        <h2 class="accordion-header" id="headingOne">
                            <button class="accordion-button collapsed fw-bold text-slate-900" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                Does this system comply with the HIPAA Security Rule?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-muted">
                                Yes. The HIPAA Security Rule requires Covered Entities to protect the confidentiality, integrity, and availability of Protected Health Information (PHI) both in transit and at rest. Because FileDrop Pro encrypts patient files *locally* inside their web browser prior to transmission, patient records are fully scrambled and secure. Not even our server hosts possess the keys to read your patient's charts.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item py-2">
                        <h2 class="accordion-header" id="headingTwo">
                            <button class="accordion-button collapsed fw-bold text-slate-900" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                What if my clinic's patients are elderly or not tech-savvy?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-muted">
                                Our platform is designed specifically with accessibility in mind. Non-technical and elderly patients often struggle with traditional health portals because they forget user credentials, get locked out, or can't navigate security setup prompts. With FileDrop Pro, patients click your direct, secure link and easily drop files or insurance photos—no signup, passwords, or registration required.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item py-2">
                        <h2 class="accordion-header" id="headingThree">
                            <button class="accordion-button collapsed fw-bold text-slate-900" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                Will you sign a Business Associate Agreement (BAA)?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-muted">
                                Yes, we sign Business Associate Agreements (BAAs) for our healthcare clients on standard Pro and Enterprise subscription tiers. Additionally, because we operate on a strict zero-knowledge, end-to-end encrypted architecture, our servers never touch unencrypted PHI data payloads, providing an ultimate cryptographic safety layer for your clinic.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<footer class="bg-slate-900 text-slate-400 py-5 premium-teal-gradient border-top border-secondary">
    <div class="container text-center">
        <span class="h5 fw-bold text-white d-block mb-3"><i class="bi bi-shield-lock-fill text-accent-teal"></i> FileDrop Pro</span>
        <p class="small text-slate-400 max-w-md mx-auto">
            Zero-Knowledge, Zero-Login Patient Intake Drops. Built strictly to support HIPAA, HITECH, and patient privacy compliance rules for clinics and private practices.
        </p>
        <div class="d-flex justify-content-center gap-4 my-4 small">
            <a href="/register" class="text-accent-teal text-decoration-none">14-Day Free Trial</a>
            <span class="text-secondary">|</span>
            <a href="#calculator" class="text-slate-300 text-decoration-none">Intake Cost Estimator</a>
            <span class="text-secondary">|</span>
            <a href="#" class="text-slate-300 text-decoration-none">Privacy Policy</a>
        </div>
        <p class="fs-8 text-secondary m-0">
            &copy; 2026 FileDrop Pro Inc. All rights reserved. System encryption conforms directly to the AES-GCM 256 and RSA-OAEP envelope cryptographic standards.
        </p>
    </div>
</footer>

<!-- Bootstrap 5.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Real-Time ROI Calculator Loop
    function calculateROI() {
        const patientsInput = parseFloat(document.getElementById('calc-patients').value) || 0;
        const hourlyInput = parseFloat(document.getElementById('calc-hourly').value) || 0;

        // Estimate average staff time wasted chasing patient charts, assisting portal logins,
        // and manually saving forms per patient
        const minutesPerPatient = 15;
        const annualPatients = patientsInput * 12;
        const annualHoursSaved = Math.round((annualPatients * minutesPerPatient) / 60);
        const annualCashSaved = annualHoursSaved * hourlyInput;

        document.getElementById('calc-hours-val').textContent = `${annualHoursSaved} hrs`;
        document.getElementById('calc-cash-val').textContent = `$${annualCashSaved.toLocaleString()}`;
    }

    // Drop Zone Interactive Simulator Ceremony
    function triggerSimulateEncryption() {
        const dropZone = document.getElementById('sim-drop-zone');
        const cryptoPanel = document.getElementById('sim-crypto-panel');
        const completePanel = document.getElementById('sim-complete-panel');
        const progress = document.getElementById('sim-progress');
        const stepBadge = document.getElementById('sim-step');
        const logBox = document.getElementById('sim-log');

        dropZone.classList.add('d-none');
        cryptoPanel.classList.remove('d-none');

        // Steps mapping specifically tailored to medical patient record drops
        const stages = [
            { percent: 25, step: "Key Derivation", log: "Spinning PBKDF2 (100,000 iterations)...\nSuccessfully derived patient symmetric session keys." },
            { percent: 50, step: "AES Encryption", log: "Acquiring patient_chart_update.pdf raw data...\nApplying local AES-GCM-256 local envelope cipher." },
            { percent: 75, step: "RSA Keywrap", log: "Acquiring Clinic's Public RSA Key...\nAsymmetrically wrapping local AES session keys in browser memory." },
            { percent: 100, step: "S3 Pipeline", log: "Streaming fully encrypted binary payload block directly to secure S3 container..." }
        ];

        let stageIndex = 0;
        const interval = setInterval(() => {
            if (stageIndex < stages.length) {
                const current = stages[stageIndex];
                progress.style.width = `${current.percent}%`;
                stepBadge.textContent = current.step;
                logBox.innerHTML = current.log;
                stageIndex++;
            } else {
                clearInterval(interval);
                cryptoPanel.classList.add('d-none');
                completePanel.classList.remove('d-none');
            }
        }, 1000);
    }

    // Run Initial calculations on load
    window.onload = function() {
        calculateROI();
    };
</script>
</body>
</html>
