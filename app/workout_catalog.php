<?php

declare(strict_types=1);

/**
 * Built-in exercise knowledge base.
 *
 * The catalogue intentionally lives in source control: guides remain available
 * offline, can be translated with the rest of the product and never depend on an
 * external AI response while somebody is training.
 *
 * @return array<int,array<string,mixed>>
 */
function wk_builtin_exercise_catalog(): array
{
    $guide = static function (
        string $summaryEn,
        string $summaryEs,
        array $stepsEn,
        array $stepsEs,
        string $tipEn,
        string $tipEs,
        string $mistakeEn,
        string $mistakeEs
    ): array {
        return [
            'en' => ['summary' => $summaryEn, 'steps' => $stepsEn, 'tips' => [$tipEn], 'mistakes' => [$mistakeEn]],
            'es' => ['summary' => $summaryEs, 'steps' => $stepsEs, 'tips' => [$tipEs], 'mistakes' => [$mistakeEs]],
        ];
    };

    return [
        [
            'slug' => 'bench_press', 'names' => ['en' => 'Barbell Bench Press', 'es' => 'Press banca con barra'],
            'muscle' => 'chest', 'secondary' => ['triceps', 'shoulders'], 'type' => 'strength', 'equipment' => 'barbell', 'difficulty' => 'intermediate', 'rank_factor' => 1.0,
            'guide' => $guide(
                'A horizontal press for chest strength with strong triceps support.',
                'Empuje horizontal para desarrollar el pecho con apoyo del tríceps.',
                ['Set your eyes under the bar and plant both feet.', 'Lower the bar to the lower chest with forearms vertical.', 'Press up and slightly back without lifting your hips.'],
                ['Coloca los ojos bajo la barra y apoya bien ambos pies.', 'Baja la barra al pecho inferior con los antebrazos verticales.', 'Empuja arriba y ligeramente atrás sin despegar la cadera.'],
                'Keep the shoulder blades pulled together throughout the set.', 'Mantén las escápulas juntas durante toda la serie.',
                'Bouncing the bar or letting the elbows flare to 90 degrees.', 'Rebotar la barra o abrir los codos a 90 grados.'
            ),
        ],
        [
            'slug' => 'incline_dumbbell_press', 'names' => ['en' => 'Incline Dumbbell Press', 'es' => 'Press inclinado con mancuernas'],
            'muscle' => 'chest', 'secondary' => ['shoulders', 'triceps'], 'type' => 'strength', 'equipment' => 'dumbbell', 'difficulty' => 'intermediate', 'rank_factor' => 0.72,
            'guide' => $guide(
                'An upper-chest press that lets each arm work independently.', 'Press para la parte alta del pecho que hace trabajar cada brazo por separado.',
                ['Set the bench to roughly 30 degrees.', 'Lower the dumbbells beside the upper chest.', 'Press them up while keeping wrists stacked over elbows.'],
                ['Ajusta el banco a unos 30 grados.', 'Baja las mancuernas junto a la parte alta del pecho.', 'Empuja manteniendo las muñecas sobre los codos.'],
                'Use a controlled stretch instead of chasing excessive depth.', 'Busca un estiramiento controlado, no una profundidad excesiva.',
                'Setting the bench too upright and turning it into a shoulder press.', 'Poner el banco demasiado vertical y convertirlo en press de hombro.'
            ),
        ],
        [
            'slug' => 'push_up', 'names' => ['en' => 'Push-up', 'es' => 'Flexión'],
            'muscle' => 'chest', 'secondary' => ['triceps', 'core'], 'type' => 'bodyweight', 'equipment' => 'bodyweight', 'difficulty' => 'beginner', 'rank_factor' => 1.0,
            'guide' => $guide(
                'A bodyweight press that also challenges trunk stability.', 'Empuje con peso corporal que también exige estabilidad del tronco.',
                ['Place hands just outside shoulder width.', 'Keep a straight line from head to heels.', 'Lower the chest between the hands, then push the floor away.'],
                ['Coloca las manos algo más abiertas que los hombros.', 'Mantén una línea recta de la cabeza a los talones.', 'Baja el pecho entre las manos y empuja el suelo.'],
                'Use an elevated surface if full range breaks your posture.', 'Usa una superficie elevada si pierdes la postura.',
                'Letting the hips sag or shortening every repetition.', 'Dejar caer la cadera o recortar todas las repeticiones.'
            ),
        ],
        [
            'slug' => 'cable_fly', 'names' => ['en' => 'Cable Fly', 'es' => 'Apertura en polea'],
            'muscle' => 'chest', 'secondary' => ['shoulders'], 'type' => 'strength', 'equipment' => 'cable', 'difficulty' => 'beginner', 'rank_factor' => 0.30,
            'guide' => $guide(
                'A chest isolation movement with tension through the full arc.', 'Aislamiento de pecho con tensión durante todo el recorrido.',
                ['Stand between the pulleys with a staggered stance.', 'Keep a soft, fixed bend in the elbows.', 'Bring the hands together by sweeping the upper arms inward.'],
                ['Colócate entre las poleas con un pie adelantado.', 'Mantén una ligera flexión fija de codo.', 'Junta las manos acercando los brazos hacia dentro.'],
                'Think about hugging a barrel rather than pressing the handles.', 'Piensa en abrazar un barril, no en empujar los agarres.',
                'Turning the movement into a press by bending the elbows.', 'Convertirlo en press flexionando demasiado los codos.'
            ),
        ],
        [
            'slug' => 'pull_up', 'names' => ['en' => 'Pull-up', 'es' => 'Dominada'],
            'muscle' => 'back', 'secondary' => ['biceps', 'core'], 'type' => 'bodyweight', 'equipment' => 'bodyweight', 'difficulty' => 'intermediate', 'rank_factor' => 1.0,
            'guide' => $guide(
                'A vertical pull for lats, upper back and arm strength.', 'Tirón vertical para dorsales, espalda alta y brazos.',
                ['Hang with ribs controlled and shoulders active.', 'Drive the elbows down toward your sides.', 'Finish with the chin over the bar, then lower under control.'],
                ['Cuélgate con las costillas controladas y hombros activos.', 'Lleva los codos hacia abajo y a los costados.', 'Supera la barra con la barbilla y baja con control.'],
                'Use assistance that still allows a full range of motion.', 'Usa asistencia suficiente para completar todo el recorrido.',
                'Kipping or craning the neck to claim the repetition.', 'Balancearse o estirar el cuello para completar la repetición.'
            ),
        ],
        [
            'slug' => 'lat_pulldown', 'names' => ['en' => 'Lat Pulldown', 'es' => 'Jalón al pecho'],
            'muscle' => 'back', 'secondary' => ['biceps'], 'type' => 'strength', 'equipment' => 'cable', 'difficulty' => 'beginner', 'rank_factor' => 0.80,
            'guide' => $guide(
                'A stable vertical pull used to build the lats.', 'Tirón vertical estable para desarrollar los dorsales.',
                ['Lock the thighs under the pad and sit tall.', 'Pull the elbows toward the ribs.', 'Touch the bar near the upper chest and return slowly.'],
                ['Fija los muslos bajo el soporte y siéntate erguido.', 'Lleva los codos hacia las costillas.', 'Acerca la barra al pecho superior y vuelve lentamente.'],
                'A slight torso lean is enough; keep it consistent.', 'Una ligera inclinación del torso es suficiente.',
                'Pulling behind the neck or using a large body swing.', 'Tirar tras la nuca o balancear demasiado el cuerpo.'
            ),
        ],
        [
            'slug' => 'barbell_row', 'names' => ['en' => 'Barbell Row', 'es' => 'Remo con barra'],
            'muscle' => 'back', 'secondary' => ['biceps', 'core'], 'type' => 'strength', 'equipment' => 'barbell', 'difficulty' => 'intermediate', 'rank_factor' => 0.90,
            'guide' => $guide(
                'A free-weight horizontal pull for back thickness and bracing.', 'Tirón horizontal con peso libre para espalda y estabilidad.',
                ['Hinge until the torso is nearly parallel to the floor.', 'Brace the trunk and pull the bar toward the lower ribs.', 'Lower until the arms are long without losing the hinge.'],
                ['Inclínate desde la cadera hasta acercar el torso al suelo.', 'Bloquea el tronco y lleva la barra a las costillas bajas.', 'Baja hasta extender los brazos sin perder la posición.'],
                'Choose a load that keeps your torso angle unchanged.', 'Elige un peso que permita mantener fijo el ángulo del torso.',
                'Standing up with each rep to create momentum.', 'Levantarse en cada repetición para generar impulso.'
            ),
        ],
        [
            'slug' => 'seated_cable_row', 'names' => ['en' => 'Seated Cable Row', 'es' => 'Remo sentado en polea'],
            'muscle' => 'back', 'secondary' => ['biceps'], 'type' => 'strength', 'equipment' => 'cable', 'difficulty' => 'beginner', 'rank_factor' => 0.72,
            'guide' => $guide(
                'A supported row for the middle back and lats.', 'Remo estable para la zona media de la espalda y dorsales.',
                ['Sit tall with knees softly bent.', 'Lead with the elbows and pull toward the navel.', 'Pause briefly, then reach forward without rounding hard.'],
                ['Siéntate erguido con las rodillas ligeramente flexionadas.', 'Guía con los codos y tira hacia el ombligo.', 'Haz una pausa y vuelve sin redondear en exceso.'],
                'Let the shoulder blades move before bending the elbows.', 'Deja que se muevan las escápulas antes de flexionar los codos.',
                'Rocking the whole torso back and forth.', 'Balancear todo el torso hacia delante y atrás.'
            ),
        ],
        [
            'slug' => 'deadlift', 'names' => ['en' => 'Conventional Deadlift', 'es' => 'Peso muerto convencional'],
            'muscle' => 'back', 'secondary' => ['glutes', 'hamstrings', 'core'], 'type' => 'strength', 'equipment' => 'barbell', 'difficulty' => 'advanced', 'rank_factor' => 1.55,
            'guide' => $guide(
                'A heavy hip hinge that trains the posterior chain as one unit.', 'Bisagra pesada que entrena toda la cadena posterior.',
                ['Stand with the bar over mid-foot and grip outside the legs.', 'Pull the slack out and brace before the plates leave the floor.', 'Push the floor away and stand tall without leaning back.'],
                ['Coloca la barra sobre el mediopié y agarra fuera de las piernas.', 'Tensa la barra y bloquea el tronco antes de despegarla.', 'Empuja el suelo y termina erguido sin inclinarte atrás.'],
                'Reset your brace if the bar stops moving close to the body.', 'Reajusta la tensión si la barra deja de subir pegada al cuerpo.',
                'Jerking the bar from a rounded, loose starting position.', 'Arrancar la barra con la espalda redondeada y sin tensión.'
            ),
        ],
        [
            'slug' => 'overhead_press', 'names' => ['en' => 'Barbell Overhead Press', 'es' => 'Press militar con barra'],
            'muscle' => 'shoulders', 'secondary' => ['triceps', 'core'], 'type' => 'strength', 'equipment' => 'barbell', 'difficulty' => 'intermediate', 'rank_factor' => 0.65,
            'guide' => $guide(
                'A standing vertical press for shoulder and trunk strength.', 'Empuje vertical de pie para hombros y estabilidad.',
                ['Start with wrists over elbows and the bar at the upper chest.', 'Brace glutes and abs before pressing.', 'Move the head through once the bar clears it.'],
                ['Empieza con muñecas sobre codos y la barra en el pecho alto.', 'Contrae glúteos y abdomen antes de empujar.', 'Pasa la cabeza bajo la barra cuando esta la supere.'],
                'Keep the bar path close to your face.', 'Mantén la barra cerca de la cara.',
                'Leaning back until the movement resembles an incline press.', 'Inclinarse atrás hasta convertirlo en un press inclinado.'
            ),
        ],
        [
            'slug' => 'lateral_raise', 'names' => ['en' => 'Dumbbell Lateral Raise', 'es' => 'Elevación lateral con mancuernas'],
            'muscle' => 'shoulders', 'secondary' => [], 'type' => 'strength', 'equipment' => 'dumbbell', 'difficulty' => 'beginner', 'rank_factor' => 0.16,
            'guide' => $guide(
                'An isolation exercise for the side deltoid.', 'Ejercicio de aislamiento para el deltoide lateral.',
                ['Stand tall with a dumbbell in each hand.', 'Raise the upper arms out and slightly forward.', 'Stop around shoulder height and lower slowly.'],
                ['Ponte erguido con una mancuerna en cada mano.', 'Eleva los brazos hacia fuera y ligeramente delante.', 'Detente a la altura del hombro y baja despacio.'],
                'Lead with the elbows rather than the hands.', 'Guía el movimiento con los codos, no con las manos.',
                'Shrugging the shoulders or swinging heavy weights.', 'Encoger los hombros o balancear pesos excesivos.'
            ),
        ],
        [
            'slug' => 'face_pull', 'names' => ['en' => 'Face Pull', 'es' => 'Face pull'],
            'muscle' => 'shoulders', 'secondary' => ['back'], 'type' => 'strength', 'equipment' => 'cable', 'difficulty' => 'beginner', 'rank_factor' => 0.28,
            'guide' => $guide(
                'A cable pull for rear delts and upper-back control.', 'Tirón en polea para deltoide posterior y espalda alta.',
                ['Set the rope around face height.', 'Pull toward the eyebrows while separating the rope ends.', 'Finish with elbows high and forearms pointing upward.'],
                ['Coloca la cuerda a la altura de la cara.', 'Tira hacia las cejas separando los extremos.', 'Termina con codos altos y antebrazos hacia arriba.'],
                'Use a light load and own the end position.', 'Usa poco peso y controla la posición final.',
                'Turning it into a low row with elbows tucked.', 'Convertirlo en un remo bajo con los codos pegados.'
            ),
        ],
        [
            'slug' => 'back_squat', 'names' => ['en' => 'Barbell Back Squat', 'es' => 'Sentadilla trasera con barra'],
            'muscle' => 'quads', 'secondary' => ['glutes', 'core'], 'type' => 'strength', 'equipment' => 'barbell', 'difficulty' => 'intermediate', 'rank_factor' => 1.30,
            'guide' => $guide(
                'A foundational knee-dominant lift for legs and trunk strength.', 'Movimiento fundamental dominante de rodilla para piernas y tronco.',
                ['Set the bar securely and brace before unracking.', 'Sit between the hips while the knees track over the toes.', 'Drive through the whole foot to stand.'],
                ['Apoya bien la barra y bloquea el tronco antes de sacarla.', 'Desciende entre las caderas con las rodillas siguiendo los pies.', 'Empuja con todo el pie para subir.'],
                'Use the deepest range you can control without losing balance.', 'Usa la mayor profundidad que controles sin perder equilibrio.',
                'Collapsing the knees inward or lifting the heels.', 'Dejar caer las rodillas hacia dentro o levantar los talones.'
            ),
        ],
        [
            'slug' => 'leg_press', 'names' => ['en' => 'Leg Press', 'es' => 'Prensa de piernas'],
            'muscle' => 'quads', 'secondary' => ['glutes'], 'type' => 'strength', 'equipment' => 'machine', 'difficulty' => 'beginner', 'rank_factor' => 2.10,
            'guide' => $guide(
                'A machine press that loads the legs with external stability.', 'Empuje en máquina que carga las piernas con estabilidad externa.',
                ['Place the feet where the heels stay planted.', 'Lower until the pelvis is about to roll off the pad.', 'Press without locking the knees aggressively.'],
                ['Coloca los pies donde puedas mantener los talones apoyados.', 'Baja hasta antes de que la pelvis se despegue del respaldo.', 'Empuja sin bloquear las rodillas de forma brusca.'],
                'Adjust stance width until knees follow the toes naturally.', 'Ajusta la anchura para que las rodillas sigan a los pies.',
                'Using a depth that rounds the lower back off the pad.', 'Bajar tanto que la zona lumbar se separe del respaldo.'
            ),
        ],
        [
            'slug' => 'reverse_lunge', 'names' => ['en' => 'Reverse Lunge', 'es' => 'Zancada atrás'],
            'muscle' => 'quads', 'secondary' => ['glutes', 'core'], 'type' => 'bodyweight', 'equipment' => 'bodyweight', 'difficulty' => 'beginner', 'rank_factor' => 0.65,
            'guide' => $guide(
                'A single-leg pattern for strength, balance and hip control.', 'Patrón unilateral para fuerza, equilibrio y control de cadera.',
                ['Step one foot back and land on the ball of the foot.', 'Lower both knees while keeping the front foot planted.', 'Drive through the front leg to return.'],
                ['Da un paso atrás y apoya la punta del pie.', 'Baja ambas rodillas manteniendo firme el pie delantero.', 'Empuja con la pierna delantera para volver.'],
                'Use a rail for balance before adding load.', 'Usa un apoyo para el equilibrio antes de añadir peso.',
                'Taking such a narrow step that the feet cross like a tightrope.', 'Cruzar demasiado los pies como si caminaras sobre una cuerda.'
            ),
        ],
        [
            'slug' => 'leg_extension', 'names' => ['en' => 'Leg Extension', 'es' => 'Extensión de cuádriceps'],
            'muscle' => 'quads', 'secondary' => [], 'type' => 'strength', 'equipment' => 'machine', 'difficulty' => 'beginner', 'rank_factor' => 0.55,
            'guide' => $guide(
                'A machine isolation exercise for the quadriceps.', 'Aislamiento en máquina para los cuádriceps.',
                ['Align the machine pivot with the knee.', 'Extend the knees while keeping hips against the pad.', 'Pause near the top and lower under control.'],
                ['Alinea el eje de la máquina con la rodilla.', 'Extiende las rodillas manteniendo la cadera en el asiento.', 'Pausa arriba y baja con control.'],
                'Control the last third of the lowering phase.', 'Controla especialmente el último tercio de la bajada.',
                'Kicking the weight up with momentum.', 'Lanzar el peso con impulso.'
            ),
        ],
        [
            'slug' => 'romanian_deadlift', 'names' => ['en' => 'Romanian Deadlift', 'es' => 'Peso muerto rumano'],
            'muscle' => 'hamstrings', 'secondary' => ['glutes', 'back'], 'type' => 'strength', 'equipment' => 'barbell', 'difficulty' => 'intermediate', 'rank_factor' => 1.05,
            'guide' => $guide(
                'A controlled hip hinge for hamstrings and glutes.', 'Bisagra controlada para isquios y glúteos.',
                ['Start tall with softly bent knees.', 'Push the hips back while sliding the bar down the thighs.', 'Stop when the hamstrings limit the hinge, then squeeze up.'],
                ['Empieza erguido con las rodillas ligeramente flexionadas.', 'Lleva la cadera atrás mientras la barra baja pegada a los muslos.', 'Para al llegar al límite de los isquios y extiende la cadera.'],
                'The bar should remain almost in contact with the legs.', 'La barra debe permanecer casi en contacto con las piernas.',
                'Squatting the weight down instead of hinging at the hips.', 'Bajar en sentadilla en lugar de hacer una bisagra de cadera.'
            ),
        ],
        [
            'slug' => 'hip_thrust', 'names' => ['en' => 'Barbell Hip Thrust', 'es' => 'Hip thrust con barra'],
            'muscle' => 'glutes', 'secondary' => ['hamstrings'], 'type' => 'strength', 'equipment' => 'barbell', 'difficulty' => 'intermediate', 'rank_factor' => 1.35,
            'guide' => $guide(
                'A loaded hip extension focused on the glutes.', 'Extensión de cadera con carga enfocada en glúteos.',
                ['Place the shoulder blades against a stable bench.', 'Drive through the feet and extend the hips.', 'Finish with ribs down and shins close to vertical.'],
                ['Apoya las escápulas en un banco estable.', 'Empuja con los pies y extiende la cadera.', 'Termina con costillas abajo y tibias casi verticales.'],
                'Pause for one second at full hip extension.', 'Haz una pausa de un segundo arriba.',
                'Overextending the lower back instead of the hips.', 'Hiperextender la zona lumbar en vez de la cadera.'
            ),
        ],
        [
            'slug' => 'leg_curl', 'names' => ['en' => 'Leg Curl', 'es' => 'Curl femoral'],
            'muscle' => 'hamstrings', 'secondary' => [], 'type' => 'strength', 'equipment' => 'machine', 'difficulty' => 'beginner', 'rank_factor' => 0.48,
            'guide' => $guide(
                'A machine knee-flexion exercise for the hamstrings.', 'Flexión de rodilla en máquina para los isquios.',
                ['Align the knee with the machine pivot.', 'Curl through the largest comfortable range.', 'Lower slowly without letting the stack crash.'],
                ['Alinea la rodilla con el eje de la máquina.', 'Flexiona usando el mayor recorrido cómodo.', 'Baja despacio sin dejar caer las placas.'],
                'Keep the hips heavy against the pad.', 'Mantén la cadera apoyada contra el asiento.',
                'Arching the back to force the last repetitions.', 'Arquear la espalda para forzar las últimas repeticiones.'
            ),
        ],
        [
            'slug' => 'bulgarian_split_squat', 'names' => ['en' => 'Bulgarian Split Squat', 'es' => 'Sentadilla búlgara'],
            'muscle' => 'glutes', 'secondary' => ['quads', 'core'], 'type' => 'strength', 'equipment' => 'dumbbell', 'difficulty' => 'intermediate', 'rank_factor' => 0.70,
            'guide' => $guide(
                'A demanding single-leg squat for glutes and quads.', 'Sentadilla unilateral exigente para glúteos y cuádriceps.',
                ['Place the rear foot on a low bench.', 'Lower the rear knee while the front foot stays planted.', 'Drive through the front leg and keep the pelvis level.'],
                ['Apoya el pie trasero en un banco bajo.', 'Baja la rodilla trasera manteniendo firme el pie delantero.', 'Empuja con la pierna delantera y mantén la pelvis nivelada.'],
                'Find your stance without weight before loading it.', 'Encuentra la distancia correcta sin peso antes de cargar.',
                'Standing too close to the bench and losing front-foot pressure.', 'Colocarse demasiado cerca del banco y perder apoyo delantero.'
            ),
        ],
        [
            'slug' => 'dumbbell_curl', 'names' => ['en' => 'Dumbbell Curl', 'es' => 'Curl con mancuernas'],
            'muscle' => 'biceps', 'secondary' => ['forearms'], 'type' => 'strength', 'equipment' => 'dumbbell', 'difficulty' => 'beginner', 'rank_factor' => 0.24,
            'guide' => $guide(
                'A simple elbow-flexion exercise for the biceps.', 'Flexión de codo sencilla para el bíceps.',
                ['Stand tall with arms long at the sides.', 'Curl without moving the upper arms forward.', 'Squeeze briefly and lower to full extension.'],
                ['Ponte erguido con los brazos extendidos.', 'Flexiona sin adelantar la parte superior del brazo.', 'Aprieta arriba y baja hasta extender por completo.'],
                'Alternate arms if both sides together make you swing.', 'Alterna brazos si al mover ambos te balanceas.',
                'Driving the elbows forward or using hip momentum.', 'Adelantar los codos o impulsarse con la cadera.'
            ),
        ],
        [
            'slug' => 'hammer_curl', 'names' => ['en' => 'Hammer Curl', 'es' => 'Curl martillo'],
            'muscle' => 'biceps', 'secondary' => ['forearms'], 'type' => 'strength', 'equipment' => 'dumbbell', 'difficulty' => 'beginner', 'rank_factor' => 0.26,
            'guide' => $guide(
                'A neutral-grip curl for biceps and forearms.', 'Curl con agarre neutro para bíceps y antebrazo.',
                ['Hold the dumbbells with palms facing inward.', 'Flex the elbows while keeping wrists neutral.', 'Lower until the arms are straight.'],
                ['Sujeta las mancuernas con las palmas enfrentadas.', 'Flexiona los codos manteniendo las muñecas neutras.', 'Baja hasta extender los brazos.'],
                'Keep the thumb pointing upward throughout.', 'Mantén el pulgar apuntando arriba.',
                'Bending the wrists toward the shoulders.', 'Doblar las muñecas hacia los hombros.'
            ),
        ],
        [
            'slug' => 'cable_pushdown', 'names' => ['en' => 'Cable Triceps Pushdown', 'es' => 'Extensión de tríceps en polea'],
            'muscle' => 'triceps', 'secondary' => [], 'type' => 'strength', 'equipment' => 'cable', 'difficulty' => 'beginner', 'rank_factor' => 0.34,
            'guide' => $guide(
                'A stable elbow-extension exercise for the triceps.', 'Extensión de codo estable para el tríceps.',
                ['Stand close to the cable with elbows at the sides.', 'Extend the elbows until the arms are straight.', 'Return without letting the upper arms drift forward.'],
                ['Ponte cerca de la polea con los codos junto al cuerpo.', 'Extiende los codos hasta estirar los brazos.', 'Vuelve sin dejar que los brazos se adelanten.'],
                'Spread a rope slightly at the bottom if comfortable.', 'Separa ligeramente la cuerda abajo si resulta cómodo.',
                'Using the shoulders and torso to force the handle down.', 'Usar hombros y torso para bajar el agarre.'
            ),
        ],
        [
            'slug' => 'overhead_triceps_extension', 'names' => ['en' => 'Overhead Triceps Extension', 'es' => 'Extensión de tríceps sobre la cabeza'],
            'muscle' => 'triceps', 'secondary' => ['shoulders'], 'type' => 'strength', 'equipment' => 'cable', 'difficulty' => 'beginner', 'rank_factor' => 0.30,
            'guide' => $guide(
                'An overhead elbow extension that lengthens the long head of the triceps.', 'Extensión sobre la cabeza que trabaja el tríceps en posición alargada.',
                ['Face away from a low cable and bring the rope overhead.', 'Keep the ribs down and elbows pointing forward.', 'Extend the arms, then return to a comfortable stretch.'],
                ['Da la espalda a una polea baja y lleva la cuerda sobre la cabeza.', 'Mantén costillas abajo y codos hacia delante.', 'Extiende los brazos y vuelve a un estiramiento cómodo.'],
                'Use a staggered stance to keep the trunk stable.', 'Usa una postura escalonada para estabilizar el tronco.',
                'Flaring the ribs and turning it into a backbend.', 'Abrir las costillas y arquear toda la espalda.'
            ),
        ],
        [
            'slug' => 'assisted_dip', 'names' => ['en' => 'Assisted Dip', 'es' => 'Fondos asistidos'],
            'muscle' => 'triceps', 'secondary' => ['chest', 'shoulders'], 'type' => 'bodyweight', 'equipment' => 'machine', 'difficulty' => 'intermediate', 'rank_factor' => 0.85,
            'guide' => $guide(
                'A supported dip for triceps and lower-chest strength.', 'Fondo con asistencia para tríceps y parte inferior del pecho.',
                ['Support yourself with shoulders down away from the ears.', 'Lower until the upper arms are roughly parallel to the floor.', 'Press the bars down to return.'],
                ['Sujétate con los hombros alejados de las orejas.', 'Baja hasta dejar los brazos aproximadamente paralelos al suelo.', 'Empuja las barras hacia abajo para volver.'],
                'Use more assistance before shortening the range.', 'Añade asistencia antes de recortar el recorrido.',
                'Dropping into a depth the shoulders cannot control.', 'Caer a una profundidad que los hombros no controlan.'
            ),
        ],
        [
            'slug' => 'plank', 'names' => ['en' => 'Front Plank', 'es' => 'Plancha frontal'],
            'muscle' => 'core', 'secondary' => ['shoulders', 'glutes'], 'type' => 'isometric', 'equipment' => 'bodyweight', 'difficulty' => 'beginner', 'rank_factor' => 1.0,
            'guide' => $guide(
                'An isometric brace that teaches the trunk to resist extension.', 'Isométrico que enseña al tronco a resistir la extensión.',
                ['Place elbows below shoulders.', 'Squeeze glutes and draw the ribs toward the pelvis.', 'Hold while breathing quietly behind the brace.'],
                ['Coloca los codos bajo los hombros.', 'Aprieta glúteos y acerca las costillas a la pelvis.', 'Mantén la tensión respirando de forma controlada.'],
                'End the set when the hips or lower back change position.', 'Termina la serie cuando cambie la posición de cadera o lumbar.',
                'Holding longer by letting the lower back sag.', 'Aguantar más tiempo dejando caer la zona lumbar.'
            ),
        ],
        [
            'slug' => 'hanging_knee_raise', 'names' => ['en' => 'Hanging Knee Raise', 'es' => 'Elevación de rodillas colgado'],
            'muscle' => 'core', 'secondary' => ['forearms'], 'type' => 'bodyweight', 'equipment' => 'bodyweight', 'difficulty' => 'intermediate', 'rank_factor' => 1.0,
            'guide' => $guide(
                'A hanging core movement that finishes with pelvic flexion.', 'Ejercicio colgado de core que termina flexionando la pelvis.',
                ['Hang with the shoulders active.', 'Bring the knees toward the chest without swinging.', 'Curl the pelvis slightly, then lower slowly.'],
                ['Cuélgate con los hombros activos.', 'Lleva las rodillas al pecho sin balancearte.', 'Enrolla ligeramente la pelvis y baja despacio.'],
                'Pause between reps until the body stops swinging.', 'Pausa entre repeticiones hasta detener el balanceo.',
                'Using momentum and only flexing the hips.', 'Usar impulso y flexionar únicamente la cadera.'
            ),
        ],
        [
            'slug' => 'cable_crunch', 'names' => ['en' => 'Cable Crunch', 'es' => 'Crunch en polea'],
            'muscle' => 'core', 'secondary' => [], 'type' => 'strength', 'equipment' => 'cable', 'difficulty' => 'beginner', 'rank_factor' => 0.45,
            'guide' => $guide(
                'A loaded trunk-flexion exercise for the abdominals.', 'Flexión de tronco con carga para los abdominales.',
                ['Kneel below a high cable with the rope near the head.', 'Keep the hips mostly fixed.', 'Bring the ribs toward the pelvis, then uncurl slowly.'],
                ['Arrodíllate bajo una polea alta con la cuerda junto a la cabeza.', 'Mantén la cadera casi fija.', 'Acerca las costillas a la pelvis y vuelve despacio.'],
                'Let the spine flex instead of pulling with the arms.', 'Deja que flexione la columna en vez de tirar con los brazos.',
                'Sitting the hips back and turning it into a pulldown.', 'Llevar la cadera atrás y convertirlo en un jalón.'
            ),
        ],
        [
            'slug' => 'standing_calf_raise', 'names' => ['en' => 'Standing Calf Raise', 'es' => 'Elevación de gemelos de pie'],
            'muscle' => 'calves', 'secondary' => [], 'type' => 'strength', 'equipment' => 'machine', 'difficulty' => 'beginner', 'rank_factor' => 0.85,
            'guide' => $guide(
                'A straight-knee calf exercise through a large ankle range.', 'Ejercicio de gemelos con rodilla extendida y recorrido amplio.',
                ['Place the balls of the feet securely on the platform.', 'Lower the heels under control.', 'Rise as high as possible without bouncing.'],
                ['Apoya con seguridad la parte delantera del pie.', 'Baja los talones de forma controlada.', 'Sube todo lo posible sin rebotar.'],
                'Pause both in the stretch and at the top.', 'Haz una pausa abajo y arriba.',
                'Using short, fast repetitions with no stretch.', 'Hacer repeticiones cortas y rápidas sin estiramiento.'
            ),
        ],
        [
            'slug' => 'running', 'names' => ['en' => 'Running', 'es' => 'Carrera'],
            'muscle' => 'cardio', 'secondary' => ['quads', 'calves'], 'type' => 'cardio', 'equipment' => 'none', 'difficulty' => 'beginner', 'rank_factor' => 1.0, 'rankable' => false,
            'guide' => $guide(
                'Continuous running for cardiovascular fitness and work capacity.', 'Carrera continua para capacidad cardiovascular y resistencia.',
                ['Begin at a pace where breathing stays controlled.', 'Land close beneath the body with relaxed shoulders.', 'Increase duration before chasing speed.'],
                ['Empieza a un ritmo con respiración controlada.', 'Apoya el pie cerca de debajo del cuerpo y relaja hombros.', 'Aumenta la duración antes de buscar velocidad.'],
                'Use the talk test to keep easy sessions easy.', 'Usa la prueba del habla para controlar los rodajes suaves.',
                'Starting every run too fast to sustain.', 'Empezar todos los rodajes a un ritmo imposible de mantener.'
            ),
        ],
        [
            'slug' => 'cycling', 'names' => ['en' => 'Stationary Cycling', 'es' => 'Bicicleta estática'],
            'muscle' => 'cardio', 'secondary' => ['quads', 'glutes'], 'type' => 'cardio', 'equipment' => 'machine', 'difficulty' => 'beginner', 'rank_factor' => 1.0, 'rankable' => false,
            'guide' => $guide(
                'Low-impact conditioning performed on a stationary bike.', 'Trabajo cardiovascular de bajo impacto en bicicleta estática.',
                ['Set the saddle so the knee remains slightly bent at the bottom.', 'Pedal smoothly without rocking the hips.', 'Choose resistance that keeps cadence controlled.'],
                ['Ajusta el sillín para dejar la rodilla algo flexionada abajo.', 'Pedalea de forma fluida sin balancear la cadera.', 'Elige una resistencia que permita controlar la cadencia.'],
                'Record both time and perceived effort for progression.', 'Registra tiempo y esfuerzo percibido para progresar.',
                'Setting the saddle too low and compressing the knees.', 'Colocar el sillín demasiado bajo y comprimir las rodillas.'
            ),
        ],
        [
            'slug' => 'rowing_ergometer', 'names' => ['en' => 'Rowing Ergometer', 'es' => 'Remo ergómetro'],
            'muscle' => 'cardio', 'secondary' => ['back', 'quads', 'core'], 'type' => 'cardio', 'equipment' => 'machine', 'difficulty' => 'intermediate', 'rank_factor' => 1.0, 'rankable' => false,
            'guide' => $guide(
                'Full-body conditioning that combines leg drive and pulling.', 'Trabajo cardiovascular de cuerpo completo con piernas y tirón.',
                ['Start compressed with arms long and trunk braced.', 'Push with the legs before opening the hips and pulling.', 'Return arms, hinge forward, then bend the knees.'],
                ['Empieza recogido con brazos largos y tronco firme.', 'Empuja con las piernas antes de abrir la cadera y tirar.', 'Vuelve con brazos, inclina el torso y después flexiona rodillas.'],
                'Most of the power should come from the legs.', 'La mayor parte de la potencia debe venir de las piernas.',
                'Pulling early with the arms and rushing the recovery.', 'Tirar demasiado pronto con brazos y volver con prisa.'
            ),
        ],
    ];
}

/**
 * Editable starter plans. Each plan creates normal user-owned routines; after
 * creation there is no special-case behaviour or vendor lock-in.
 *
 * @return array<string,array<string,mixed>>
 */
function wk_builtin_plan_presets(): array
{
    return [
        'full_body' => [
            'name' => ['en' => 'Full body starter', 'es' => 'Inicio full body'],
            'description' => ['en' => 'Three balanced sessions per week.', 'es' => 'Tres sesiones equilibradas por semana.'],
            'routines' => [[
                'name' => ['en' => 'Full body', 'es' => 'Cuerpo completo'],
                'days' => '1010100',
                'exercises' => [
                    ['back_squat', 3, 8], ['bench_press', 3, 8], ['lat_pulldown', 3, 10],
                    ['romanian_deadlift', 3, 10], ['overhead_press', 2, 10], ['plank', 3, 30],
                ],
            ]],
        ],
        'push_pull_legs' => [
            'name' => ['en' => 'Push / Pull / Legs', 'es' => 'Empuje / Tirón / Pierna'],
            'description' => ['en' => 'A six-day split with one focus per session.', 'es' => 'División de seis días con un foco por sesión.'],
            'routines' => [
                ['name' => ['en' => 'Push', 'es' => 'Empuje'], 'days' => '1001000', 'exercises' => [['bench_press', 3, 8], ['incline_dumbbell_press', 3, 10], ['overhead_press', 3, 8], ['lateral_raise', 3, 12], ['cable_pushdown', 3, 12]]],
                ['name' => ['en' => 'Pull', 'es' => 'Tirón'], 'days' => '0100100', 'exercises' => [['deadlift', 3, 5], ['lat_pulldown', 3, 10], ['seated_cable_row', 3, 10], ['face_pull', 3, 12], ['dumbbell_curl', 3, 12]]],
                ['name' => ['en' => 'Legs', 'es' => 'Piernas'], 'days' => '0010010', 'exercises' => [['back_squat', 3, 8], ['romanian_deadlift', 3, 10], ['leg_press', 3, 10], ['leg_curl', 3, 12], ['standing_calf_raise', 3, 15]]],
            ],
        ],
        'upper_lower' => [
            'name' => ['en' => 'Upper / Lower', 'es' => 'Torso / Pierna'],
            'description' => ['en' => 'Four weekly sessions with extra recovery.', 'es' => 'Cuatro sesiones semanales con más recuperación.'],
            'routines' => [
                ['name' => ['en' => 'Upper body', 'es' => 'Torso'], 'days' => '1001000', 'exercises' => [['bench_press', 3, 8], ['barbell_row', 3, 8], ['overhead_press', 3, 10], ['lat_pulldown', 3, 10], ['lateral_raise', 2, 15], ['cable_pushdown', 2, 12]]],
                ['name' => ['en' => 'Lower body', 'es' => 'Pierna'], 'days' => '0100100', 'exercises' => [['back_squat', 3, 8], ['romanian_deadlift', 3, 10], ['bulgarian_split_squat', 3, 10], ['leg_curl', 3, 12], ['standing_calf_raise', 3, 15]]],
            ],
        ],
    ];
}
