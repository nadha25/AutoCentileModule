<?php
/**
 * Auto Centile Calculator
 * Automatically calculates and populates centile fields
 */

namespace YourInstitution\AutoCentileModule;

use ExternalModules\AbstractExternalModule;

class AutoCentileModule extends AbstractExternalModule {

    public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        $targetInstruments = explode(',', $this->getProjectSetting('target_instruments'));
        $targetInstruments = array_map('trim', $targetInstruments);
        
        if (in_array($instrument, $targetInstruments)) {
            $this->injectAutoCalculator();
        }
    }

    private function injectAutoCalculator() {
        $weightField = $this->getProjectSetting('weight_field') ?: 'weight_kg';
        $heightField = $this->getProjectSetting('height_field') ?: 'height_cm';
        $dobField = $this->getProjectSetting('dob_field') ?: 'date_of_birth';
        $sexField = $this->getProjectSetting('sex_field') ?: 'sex';
        $measurementDateField = $this->getProjectSetting('measurement_date_field') ?: 'measurement_date';
        $gestationWeeksField = $this->getProjectSetting('gestation_weeks_field') ?: 'gestation_weeks';
        $gestationDaysField = $this->getProjectSetting('gestation_days_field') ?: 'gestation_days';
        
        $weightCentileField = $this->getProjectSetting('weight_centile_field') ?: 'weight_centile';
        $heightCentileField = $this->getProjectSetting('height_centile_field') ?: 'height_centile';
        $bmiCentileField = $this->getProjectSetting('bmi_centile_field') ?: 'bmi_centile';
        $weightSdsField = $this->getProjectSetting('weight_sds_field') ?: 'weight_sds';
        $heightSdsField = $this->getProjectSetting('height_sds_field') ?: 'height_sds';
        $bmiSdsField = $this->getProjectSetting('bmi_sds_field') ?: 'bmi_sds';
        
        ?>
        <script>
            const fieldNames = {
                weight: '<?php echo $weightField; ?>',
                height: '<?php echo $heightField; ?>',
                dob: '<?php echo $dobField; ?>',
                sex: '<?php echo $sexField; ?>',
                measurementDate: '<?php echo $measurementDateField; ?>',
                gestationWeeks: '<?php echo $gestationWeeksField; ?>',
                gestationDays: '<?php echo $gestationDaysField; ?>',
                weightCentile: '<?php echo $weightCentileField; ?>',
                heightCentile: '<?php echo $heightCentileField; ?>',
                bmiCentile: '<?php echo $bmiCentileField; ?>',
                weightSds: '<?php echo $weightSdsField; ?>',
                heightSds: '<?php echo $heightSdsField; ?>',
                bmiSds: '<?php echo $bmiSdsField; ?>'
            };

            let calculateTimeout;

            function getFieldValue(fieldName) {
                const field = document.querySelector(`[name="${fieldName}"]`);
                return field ? field.value : '';
            }

            function getRadioValue(fieldName) {
                const radio = document.querySelector(`input[name="${fieldName}"]:checked`);
                return radio ? radio.value : '';
            }

            function setFieldValue(fieldName, value) {
                const field = document.querySelector(`[name="${fieldName}"]`);
                if (field) {
                    field.value = value;
                    $(field).trigger('change');
                }
            }

            async function autoCalculateCentiles() {
                const weight = getFieldValue(fieldNames.weight);
                const height = getFieldValue(fieldNames.height);
                const dob = getFieldValue(fieldNames.dob);
                const sex = getRadioValue(fieldNames.sex);
                const measurementDate = getFieldValue(fieldNames.measurementDate);

                if (!dob || !sex || !measurementDate) return;
                if (!weight && !height) return;

                try {
                    const formData = {
                        birth_date: dob,
                        measurement_date: measurementDate,
                        weight: weight,
                        height: height,
                        sex: sex,
                        gestation_weeks: getFieldValue(fieldNames.gestationWeeks),
                        gestation_days: getFieldValue(fieldNames.gestationDays),
                        measurement_method: 'height'
                    };

                    const response = await fetch('<?php echo $this->getUrl('ajax/calculate_centiles.php'); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(formData)
                    });

                    if (!response.ok) {
                        console.error('Centile calculation failed');
                        return;
                    }

                    const data = await response.json();

                    if (!data.success) {
                        console.error('Centile calculation error:', data.error);
                        return;
                    }

                    const results = data.results;

                    if (results.weight && !results.weight.error) {
                        const centile = Math.round(results.weight.centile * 10) / 10;
                        const sds = Math.round(results.weight.sds * 100) / 100;
                        setFieldValue(fieldNames.weightCentile, centile);
                        setFieldValue(fieldNames.weightSds, sds);
                    }

                    if (results.height && !results.height.error) {
                        const centile = Math.round(results.height.centile * 10) / 10;
                        const sds = Math.round(results.height.sds * 100) / 100;
                        setFieldValue(fieldNames.heightCentile, centile);
                        setFieldValue(fieldNames.heightSds, sds);
                    }

                    if (results.bmi && !results.bmi.error) {
                        const centile = Math.round(results.bmi.centile * 10) / 10;
                        const sds = Math.round(results.bmi.sds * 100) / 100;
                        setFieldValue(fieldNames.bmiCentile, centile);
                        setFieldValue(fieldNames.bmiSds, sds);
                    }

                } catch (error) {
                    console.error('Centile calculation error:', error);
                }
            }

            function scheduleCalculation() {
                clearTimeout(calculateTimeout);
                calculateTimeout = setTimeout(autoCalculateCentiles, 1000);
            }

            $(document).ready(function() {
                const watchFields = [
                    fieldNames.weight,
                    fieldNames.height,
                    fieldNames.measurementDate
                ];

                watchFields.forEach(fieldName => {
                    $(`[name="${fieldName}"]`).on('change blur', scheduleCalculation);
                });

                $(`input[name="${fieldNames.sex}"]`).on('change', scheduleCalculation);

                setTimeout(autoCalculateCentiles, 500);
            });
        </script>
        <?php
    }
}
