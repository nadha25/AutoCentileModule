<?php
/**
 * Auto Centile Calculator
 * Automatically calculates and populates centile fields using RCPCH API
 */

namespace ResearchFIRST\AutoCentileModule;

use ExternalModules\AbstractExternalModule;

class AutoCentileModule extends AbstractExternalModule {

    /**
     * Hook: redcap_data_entry_form
     * Injects JavaScript calculator on configured instruments
     */
    public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        $targetInstruments = $this->getProjectSetting('target_instruments');
        
        if (empty($targetInstruments)) {
            return; // No instruments configured
        }
        
        $targetInstruments = explode(',', $targetInstruments);
        $targetInstruments = array_map('trim', $targetInstruments);
        
        if (in_array($instrument, $targetInstruments)) {
            $this->injectAutoCalculator();
        }
    }

    /**
     * Inject JavaScript for automatic centile calculation
     */
    private function injectAutoCalculator() {
        // Get field configuration
        $weightField = $this->getProjectSetting('weight_field');
        $heightField = $this->getProjectSetting('height_field');
        $dobField = $this->getProjectSetting('dob_field');
        $sexField = $this->getProjectSetting('sex_field');
        $measurementDateField = $this->getProjectSetting('measurement_date_field');
        $gestationWeeksField = $this->getProjectSetting('gestation_weeks_field');
        $gestationDaysField = $this->getProjectSetting('gestation_days_field');
        
        // Output fields
        $weightCentileField = $this->getProjectSetting('weight_centile_field');
        $heightCentileField = $this->getProjectSetting('height_centile_field');
        $bmiCentileField = $this->getProjectSetting('bmi_centile_field');
        $weightSdsField = $this->getProjectSetting('weight_sds_field');
        $heightSdsField = $this->getProjectSetting('height_sds_field');
        $bmiSdsField = $this->getProjectSetting('bmi_sds_field');
        
        // Validate required fields are configured
        if (empty($dobField) || empty($sexField) || empty($measurementDateField)) {
            error_log('Auto Centile Module: Required fields (DOB, Sex, Measurement Date) not configured');
            return;
        }
        
        // Get date validation formats from data dictionary (for better date parsing)
        $dobValidation = $this->getFieldValidationType($dobField);
        $measurementDateValidation = $this->getFieldValidationType($measurementDateField);
        
        // Get AJAX endpoint URL
       $ajaxUrl = $this->getUrl('ajax/calculate_centiles.php', true, true);
        
        ?>
        <script>
            (function() {
                'use strict';
                
                const fieldNames = {
                    weight: <?php echo json_encode($weightField); ?>,
                    height: <?php echo json_encode($heightField); ?>,
                    dob: <?php echo json_encode($dobField); ?>,
                    sex: <?php echo json_encode($sexField); ?>,
                    measurementDate: <?php echo json_encode($measurementDateField); ?>,
                    gestationWeeks: <?php echo json_encode($gestationWeeksField); ?>,
                    gestationDays: <?php echo json_encode($gestationDaysField); ?>,
                    weightCentile: <?php echo json_encode($weightCentileField); ?>,
                    heightCentile: <?php echo json_encode($heightCentileField); ?>,
                    bmiCentile: <?php echo json_encode($bmiCentileField); ?>,
                    weightSds: <?php echo json_encode($weightSdsField); ?>,
                    heightSds: <?php echo json_encode($heightSdsField); ?>,
                    bmiSds: <?php echo json_encode($bmiSdsField); ?>
                };

                const dateFormats = {
                    dob: <?php echo json_encode($dobValidation); ?>,
                    measurementDate: <?php echo json_encode($measurementDateValidation); ?>
                };

                const ajaxUrl = <?php echo json_encode($ajaxUrl); ?>;
                
                let calculateTimeout;
                let isCalculating = false;

                /**
                 * Get value from a text/date field
                 */
                function getFieldValue(fieldName) {
                    if (!fieldName) return '';
                    const field = document.querySelector(`[name="${fieldName}"]`);
                    return field ? field.value.trim() : '';
                }

                /**
                 * Get value from a radio button field
                 */
                function getRadioValue(fieldName) {
                    if (!fieldName) return '';
                    const radio = document.querySelector(`input[name="${fieldName}"]:checked`);
                    return radio ? radio.value : '';
                }

                /**
                 * Set value to a field and trigger change event
                 */
                function setFieldValue(fieldName, value) {
                    if (!fieldName) return;
                    const field = document.querySelector(`[name="${fieldName}"]`);
                    if (field && field.value !== String(value)) {
                        field.value = value;
                        $(field).trigger('change');
                    }
                }

                /**
                 * Clear all centile/SDS fields
                 */
                function clearResults() {
                    setFieldValue(fieldNames.weightCentile, '');
                    setFieldValue(fieldNames.weightSds, '');
                    setFieldValue(fieldNames.heightCentile, '');
                    setFieldValue(fieldNames.heightSds, '');
                    setFieldValue(fieldNames.bmiCentile, '');
                    setFieldValue(fieldNames.bmiSds, '');
                }

                /**
                 * Main calculation function
                 */
                async function autoCalculateCentiles() {
                    if (isCalculating) {
                        console.log('Calculation already in progress, skipping...');
                        return;
                    }

                    // Get input values
                    const weight = getFieldValue(fieldNames.weight);
                    const height = getFieldValue(fieldNames.height);
                    const dob = getFieldValue(fieldNames.dob);
                    const sex = getRadioValue(fieldNames.sex);
                    const measurementDate = getFieldValue(fieldNames.measurementDate);

                    // Validate required fields
                    if (!dob || !sex || !measurementDate) {
                        console.log('Missing required fields (DOB, Sex, or Measurement Date)');
                        clearResults();
                        return;
                    }

                    // Need at least one measurement
                    if (!weight && !height) {
                        console.log('No weight or height provided');
                        clearResults();
                        return;
                    }

                    isCalculating = true;

                    try {
                        const formData = {
                            birth_date: dob,
                            measurement_date: measurementDate,
                            weight: weight,
                            height: height,
                            sex: sex,
                            gestation_weeks: getFieldValue(fieldNames.gestationWeeks),
                            gestation_days: getFieldValue(fieldNames.gestationDays),
                            measurement_method: 'height',
                            // Pass date format hints for better parsing
                            dob_format: dateFormats.dob,
                            measurement_date_format: dateFormats.measurementDate
                        };

                        console.log('Calculating centiles...', formData);

                        const response = await fetch(ajaxUrl, {
                            method: 'POST',
                            headers: { 
                                'Content-Type': 'application/json',
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify(formData)
                        });

                        if (!response.ok) {
                            const errorText = await response.text();
                            console.error('Centile calculation failed:', response.status, errorText);
                            clearResults();
                            return;
                        }

                        const data = await response.json();

                        if (!data.success) {
                            console.error('Centile calculation error:', data.error);
                            clearResults();
                            return;
                        }

                        console.log('Centile results:', data.results);

                        const results = data.results;

                        // Update weight centile and SDS
                        if (results.weight && !results.weight.error) {
                            if (results.weight.centile !== null) {
                                const centile = Math.round(results.weight.centile * 10) / 10;
                                setFieldValue(fieldNames.weightCentile, centile);
                            }
                            if (results.weight.sds !== null) {
                                const sds = Math.round(results.weight.sds * 100) / 100;
                                setFieldValue(fieldNames.weightSds, sds);
                            }
                        } else if (results.weight && results.weight.error) {
                            console.warn('Weight calculation error:', results.weight.error);
                        }

                        // Update height centile and SDS
                        if (results.height && !results.height.error) {
                            if (results.height.centile !== null) {
                                const centile = Math.round(results.height.centile * 10) / 10;
                                setFieldValue(fieldNames.heightCentile, centile);
                            }
                            if (results.height.sds !== null) {
                                const sds = Math.round(results.height.sds * 100) / 100;
                                setFieldValue(fieldNames.heightSds, sds);
                            }
                        } else if (results.height && results.height.error) {
                            console.warn('Height calculation error:', results.height.error);
                        }

                        // Update BMI centile and SDS
                        if (results.bmi && !results.bmi.error) {
                            if (results.bmi.centile !== null) {
                                const centile = Math.round(results.bmi.centile * 10) / 10;
                                setFieldValue(fieldNames.bmiCentile, centile);
                            }
                            if (results.bmi.sds !== null) {
                                const sds = Math.round(results.bmi.sds * 100) / 100;
                                setFieldValue(fieldNames.bmiSds, sds);
                            }
                        } else if (results.bmi && results.bmi.error) {
                            console.warn('BMI calculation error:', results.bmi.error);
                        }

                    } catch (error) {
                        console.error('Centile calculation error:', error);
                        clearResults();
                    } finally {
                        isCalculating = false;
                    }
                }

                /**
                 * Schedule calculation with debouncing
                 */
                function scheduleCalculation() {
                    clearTimeout(calculateTimeout);
                    calculateTimeout = setTimeout(autoCalculateCentiles, 1000);
                }

                /**
                 * Initialize event listeners
                 */
                $(document).ready(function() {
                    console.log('Auto Centile Calculator initialized');
                    
                    // Watch text/date fields
                    const watchFields = [
                        fieldNames.weight,
                        fieldNames.height,
                        fieldNames.dob,
                        fieldNames.measurementDate,
                        fieldNames.gestationWeeks,
                        fieldNames.gestationDays
                    ].filter(f => f); // Remove null/empty fields

                    watchFields.forEach(fieldName => {
                        const field = $(`[name="${fieldName}"]`);
                        if (field.length) {
                            field.on('change blur keyup', scheduleCalculation);
                        }
                    });

                    // Watch sex radio buttons
                    if (fieldNames.sex) {
                        $(`input[name="${fieldNames.sex}"]`).on('change', scheduleCalculation);
                    }

                    // Initial calculation on page load (if fields already have values)
                    setTimeout(autoCalculateCentiles, 500);
                });
            })();
        </script>
        <style>
            /* Optional: Add visual indicator for auto-calculated fields */
            <?php 
            $autoFields = array_filter([
                $weightCentileField, 
                $heightCentileField, 
                $bmiCentileField,
                $weightSdsField,
                $heightSdsField,
                $bmiSdsField
            ]);
            
            foreach ($autoFields as $field): 
            ?>
            input[name="<?php echo $field; ?>"] {
                background-color: #f0f8ff !important;
                border-left: 3px solid #4CAF50 !important;
            }
            <?php endforeach; ?>
        </style>
        <?php
    }

    /**
     * Get validation type for a field from data dictionary
     * @param string $fieldName
     * @return string|null Returns validation type (e.g., 'date_dmy', 'date_mdy', 'date_ymd') or null
     */
    private function getFieldValidationType($fieldName) {
        if (empty($fieldName)) {
            return null;
        }
        
        try {
            // Get data dictionary for current project
            $dataDictionary = \REDCap::getDataDictionary('array');
            
            if (isset($dataDictionary[$fieldName]['text_validation_type_or_show_slider_number'])) {
                return $dataDictionary[$fieldName]['text_validation_type_or_show_slider_number'];
            }
        } catch (\Exception $e) {
            error_log('Auto Centile Module: Error getting validation type for field ' . $fieldName . ': ' . $e->getMessage());
        }
        
        return null;
    }
}
