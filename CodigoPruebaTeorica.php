
<?php 

class TeacherSchedulesController extends \AppController {

	public function save_add() {
		$idClient = \CigarrilloBuilder::get('idClient');
		$teachers = $this->TeacherSchedule->Person->find('list', [
			'fields' => ['id', 'nombre_completo'],
			'conditions' => ['clases_virtuales' => 1, 'id_cliente' => $idClient],
			'order' => 'nombre_completo ASC'
		]);

		$data = $this->request->input('json_decode', true);

		$errorCode = '';
		$unitVenues = [];

		if (!empty($data)) {
			$dataSource = $this->TeacherSchedule->getDataSource();
			$dataSource->begin();
			$error = false;
			$days = [];
			$teacherSchedule = $data['TeacherSchedule'];

			if ($teacherSchedule['fecha_inicio'] != '') {

				/**
				 *  Explicacion Codigo
				 *		
				 *	Se checkea la fecha de inicio para saber si es válida.
				 *	La fecha de inicio debe ser mayor a la fecha actual (hoy). 
				 *	Si alguno de estos escenarios no ocurre devolverá: 
				 *	Error: La fecha de inicio es inválida
				 */
				$initParts = explode('-', $teacherSchedule['fecha_inicio']);
				$validInit = checkdate($initParts[1], $initParts[2], $initParts[0]);
				if ($teacherSchedule['fecha_inicio'] < date('Y-m-d')) {
					$validInit = false;
				}
				/** Fin explicación **/

				if ($validInit) {
					$saveType = 1;
					$days[] = $teacherSchedule['fecha_inicio'];
					if ($teacherSchedule['fecha_fin'] != '') {

						$endParts = explode('-', $teacherSchedule['fecha_fin']);
						$validEnd = checkdate($endParts[1], $endParts[2], $endParts[0]);
						if ($teacherSchedule['fecha_fin'] < date('Y-m-d')) {
							$validEnd = false;
						}

						if ($validEnd) {

							/**
							 *  Explicacion Codigo
							 *
							 *	Si la fecha de inicio en menor o igual a la fecha de fin se obtiene todas las fechas correspondiente
							 *	desde el dia siguiente a la fecha de inicio hasta la fecha de fin.
							 *	En caso de que la fecha de inicio sea mayor a la fecha de fin devolverá: 
							 *	'Error: La fecha de fin es anterior a la fecha de inicio'
							 */
							if ($teacherSchedule['fecha_inicio'] <= $teacherSchedule['fecha_fin']) {

								$currentDay = $teacherSchedule['fecha_inicio'];
								while ($currentDay != $teacherSchedule['fecha_fin']) {
									$currentDay = date('Y-m-d', strtotime('next day', strtotime($currentDay)));
									$days[] = $currentDay;
								}
							}
							else {
								$error = __('Error: La fecha de fin es anterior a la fecha de inicio');
							}
							/** Fin explicación **/
						}
						else {
							$error = __('Error: La fecha de fin es inválida');
						}
					}
				}
				else {
					$error = __('Error: La fecha de inicio es inválida');
				}

			} else if (!empty($teacherSchedule['dias_semana'])) {
				$saveType = 2; //Guarda días
				$days = $teacherSchedule['dias_semana'];
				if (count(array_diff($days, [1, 2, 3, 4, 5, 6, 7])) > 0) {
					$error = __('Error: Los días no son válidos');
				}
			}
			else {
				$error = __('Debe seleccionar un día de semana o una fecha');
			}

			if ($teacherSchedule['disponible'] == 0 && !$error) {
				

				if ($saveType == 1) {

					/**
					 *  Explicacion Codigo
					 *
					 *	Arreglo de registros  de modelos asociados al id de la persona 
					 *	 fechas para horario fijo
					 */
					$memberUnitVenues = $this->TeacherSchedule->Person->Member->MemberUnitVenue->find('all', [
						'conditions' => [
							'fecha' => $days,
							'Member.id_persona' => $teacherSchedule['id_persona'],
							'estado' => [0, 1]
						],
						'contain' => [
							'Member'
						]
					]);
					/** Fin explicación **/

				} else {
					
					/**
					 *  Explicacion Codigo
					 *
					 *	Arreglo de registros  de modelos asociados al id de la persona 
					 *	donde la fecha es mayor o igual al fecha actual (hoy)  y dias de la semana
					 *	para horario habitual
					 */
					$memberUnitVenues = $this->TeacherSchedule->Person->Member->MemberUnitVenue->find('all', [
						'conditions' => [
							'fecha >=' => date('Y-m-d'),
							'weekday(fecha)+1' => $days,
							'Member.id_persona' => $teacherSchedule['id_persona'],
							'estado' => [0, 1]
						],
						'contain' => [
							'Member'
						]
					]);
					/** Fin explicación **/
				}

				if (!$error) {
					$init = date('H:i', strtotime($teacherSchedule['hora_inicio']));
					$end = date('H:i', strtotime($teacherSchedule['hora_fin']));

					//En tres casos una clase topa con un bloque
						$id = $memberUnitVenue['MemberUnitVenue']['id'];
						$memberInit = date('H:i', strtotime($memberUnitVenue['MemberUnitVenue']['hora_inicio']));
						$memberEnd = date('H:i', strtotime($memberUnitVenue['MemberUnitVenue']['hora_fin']));

						//caso 1: clase empieza dentro del bloque
						if ($memberInit >= $init && $memberInit < $end) {
							$unitVenues[$id] = $id;
						}
					foreach ($memberUnitVenues as $memberUnitVenue) {
						//caso 2: clase termina dentro del bloque
						if ($memberEnd > $init && $memberEnd <= $end) {
							$unitVenues[$id] = $id;
						}
						//caso 3: clase empieza antesd el bloque y termina después del bloque
						if ($memberInit <= $init && $memberEnd >= $end) {
							$unitVenues[$id] = $id;
						}
					}

					/**
					 *  Explicacion Codigo
					 */
					if (empty($teacherSchedule['mantener_clases'])) {
						if (count($unitVenues) > 1) {
							$error = __('El profesor tiene varias clases agendadas dentro del horario');
							$errorCode = 'MC01';
						}
						if (count($unitVenues) == 1) {
							$error = __('El profesor tiene una clase agendada dentro del horario');
							$errorCode = 'MC02';
						}
					}
					/** Fin explicación **/
				}
			}

			if ($saveType == 2) {
				
				/**
				 *  Explicacion Codigo
				 *
				 *	listado de fechas agrupadas  mayores a la fecha actual para ser asignado 
				 *	a nuevo registro de horario de profesor por su id_persona.
				 */ 
				$nextDays = $this->TeacherSchedule->find('list', [
					'fields' => ['id', 'fecha'],
					'conditions' => [
						'fecha >=' => date('Y-m-d'),
						'weekday(fecha)+1' => $days,
						'id_persona' => $teacherSchedule['id_persona'],
						'disponible !=' => $teacherSchedule['disponible']
					],
					'group' => 'fecha'
				]);
				/** Fin explicación **/

				if (!empty($nextDays)) {
					foreach ($nextDays as $id => $nextDay) {
						//guardar un registro de horario con fecha para el profesor
						$teacherSchedule['dia_semana'] = 0;
						$teacherSchedule['fecha'] = $nextDay;
						//crea horario de profesor
						$this->TeacherSchedule->id = $id;
						if (!$this->TeacherSchedule->save($teacherSchedule)) {
							$error = true;
						}
					}
				}
			}

		}
		else {
			$error = __('No se recibieron datos');
		}

		$response = [
			'error' => $error,
			'errorCode' => $errorCode,
			'unitVenues' => $unitVenues
		];

		$code = empty($error) ? 200 : 400;
		$this->set(compact('code', 'response'));
		$this->set('_serialize', ['code', 'response']);
	}

}

?>